<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\OrganizationService;
use App\Services\EntitlementService;
use App\Services\OrganizationAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly EntitlementService $entitlementService,
        private readonly OrganizationAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $organizations = $request->user()
            ->organizationMemberships()
            ->where('status', 'active')
            ->with('organization')
            ->get()
            ->map(fn ($membership) => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'logo_url' => $membership->organization->logo_url,
                'description' => $membership->organization->description,
                'industry' => $membership->organization->industry,
                'contact_email' => $membership->organization->contact_email,
                'phone' => $membership->organization->phone,
                'address' => $membership->organization->address,
                'timezone' => $membership->organization->timezone,
                'created_at' => $membership->organization->created_at?->toISOString(),
                'status' => $membership->organization->status,
                'registration_policy' => $membership->organization->registration_policy,
                'role' => $membership->role,
            ]);

        return response()->json($organizations);
    }

    public function show(Request $request, Organization $organization)
    {
        $membership = $organization->memberships()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'Organization not found or access denied.'], 403);
        }

        return response()->json([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'logo_url' => $organization->logo_url,
            'description' => $organization->description,
            'industry' => $organization->industry,
            'contact_email' => $organization->contact_email,
            'phone' => $organization->phone,
            'address' => $organization->address,
            'timezone' => $organization->timezone,
            'created_at' => $organization->created_at?->toISOString(),
            'status' => $organization->status,
            'registration_policy' => $organization->registration_policy,
            'role' => $membership->role,
        ]);
    }

    public function store(StoreOrganizationRequest $request)
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Verify your email address before creating an organization.'], 403);
        }

        $organization = DB::transaction(function () use ($user, $request) {
            $lockedUser = $user->newQuery()->lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->organizationMemberships()->exists()) {
                return null;
            }

            return $this->organizationService->createForUser($lockedUser, $request->validated());
        });

        if (! $organization) {
            return response()->json(['message' => 'An account can create an initial organization only once.'], 409);
        }

        return response()->json([
            'message' => 'Organization created successfully.',
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'registration_policy' => $organization->registration_policy,
                'role' => $organization->memberships->first()->role,
            ],
        ], 201);
    }

    public function plans(Request $request)
    {
        $plans = SubscriptionPlan::query()->where('status', 'active')->get()->map(function ($plan) {
            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'billing_cycle' => $plan->billing_cycle,
                'price' => (float) $plan->price,
                'trial_days' => $plan->trial_days,
                'features' => $plan->features()->withPivot(['enabled', 'value'])->get()->map(fn ($feature) => [
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'enabled' => (bool) $feature->pivot->enabled,
                    'limit' => (int) ($feature->pivot->value['limit'] ?? 0),
                ]),
            ];
        });

        return response()->json($plans);
    }

    public function features(Request $request)
    {
        return response()->json(
            Feature::query()->where('is_active', true)->get()->map(fn ($feature) => [
                'id' => $feature->id,
                'key' => $feature->key,
                'name' => $feature->name,
                'type' => $feature->type,
            ])
        );
    }

    public function subscription(Request $request, Organization $organization)
    {
        if (! $organization->memberships()->where('user_id', $request->user()->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $subscription = $organization->subscriptions()->latest('started_at')->first();

        return response()->json([
            'organization_id' => $organization->id,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan' => $subscription->plan->name,
                'starts_at' => $subscription->started_at?->toISOString(),
                'ends_at' => $subscription->ends_at?->toISOString(),
                'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            ] : null,
        ]);
    }

    public function activateSubscription(Request $request, Organization $organization)
    {
        $membership = $this->access->membership($request->user(), $organization);

        if (! $membership || ! $this->access->allows($membership, 'manage_company_settings')) {
            return response()->json(['message' => 'You are not authorized to manage company settings.'], 403);
        }

        $request->validate([
            'plan_id' => ['required', 'exists:subscription_plans,id'],
        ]);

        $plan = SubscriptionPlan::query()->findOrFail($request->input('plan_id'));

        $subscription = Subscription::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'started_at' => now(),
                'ends_at' => now()->addMonth(),
                'trial_ends_at' => now()->addDays($plan->trial_days),
                'price' => $plan->price,
                'currency' => 'USD',
                'metadata' => ['source' => 'api'],
            ],
        );

        return response()->json([
            'message' => 'Subscription activated.',
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan' => $plan->name,
            ],
        ], 201);
    }

    public function entitlements(Request $request, Organization $organization)
    {
        if (! $organization->memberships()->where('user_id', $request->user()->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $featureKeys = ['basic_mode', 'advanced_mode', 'bulk_predictions', 'random_forest', 'gradient_boosting', 'hyperparameter_tuning', 'model_comparison', 'advanced_metrics', 'priority_processing'];

        $entitlements = [];

        foreach ($featureKeys as $featureKey) {
            $entitlements[$featureKey] = [
                'enabled' => $this->entitlementService->hasFeature($organization, $featureKey),
                'config' => $this->entitlementService->getFeatureConfig($organization, $featureKey),
            ];
        }

        return response()->json($entitlements);
    }

    public function recordUsage(Request $request, Organization $organization)
    {
        if (! $organization->memberships()->where('user_id', $request->user()->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'feature_key' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        if (! $this->entitlementService->canUseQuota($organization, $validated['feature_key'], $validated['quantity'])) {
            return response()->json([
                'message' => 'Quota limit reached for this feature.',
            ], 429);
        }

        $event = $this->entitlementService->recordUsage($organization, $validated['feature_key'], $validated['quantity'], [
            'source' => 'api',
        ]);

        return response()->json([
            'message' => 'Usage recorded.',
            'event' => [
                'id' => $event->id,
                'feature_key' => $event->feature_key,
                'quantity' => $event->quantity,
            ],
        ], 201);
    }
}
