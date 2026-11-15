<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_plan_and_entitlements_are_available(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Beta Org',
            'slug' => 'beta-org',
            'status' => 'active',
            'registration_policy' => 'open',
            'trial_ends_at' => now()->addDays(14),
            'settings' => [],
        ]);

        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => 'organization_owner',
            'status' => 'active',
        ]);

        $plan = SubscriptionPlan::query()->where('slug', 'starter')->first();
        $organization->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => now()->addDays(14),
            'price' => $plan->price,
            'currency' => 'USD',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/organizations/'.$organization->id.'/entitlements');

        $response->assertOk()->assertJsonPath('basic_mode.enabled', true)
            ->assertJsonPath('bulk_predictions.enabled', true)
            ->assertJsonPath('advanced_mode.enabled', true);
    }

    public function test_quota_is_enforced_for_usage_events(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Quota Org',
            'slug' => 'quota-org',
            'status' => 'active',
            'registration_policy' => 'open',
            'trial_ends_at' => now()->addDays(14),
            'settings' => [],
        ]);

        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => 'organization_owner',
            'status' => 'active',
        ]);

        $plan = SubscriptionPlan::query()->where('slug', 'starter')->first();
        $organization->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => now()->addDays(14),
            'price' => $plan->price,
            'currency' => 'USD',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/organizations/'.$organization->id.'/usage', [
            'feature_key' => 'bulk_predictions',
            'quantity' => 500,
        ])->assertStatus(201);

        $this->withToken($token)->postJson('/api/organizations/'.$organization->id.'/usage', [
            'feature_key' => 'bulk_predictions',
            'quantity' => 1,
        ])->assertStatus(429);
    }
}
