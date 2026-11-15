<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Services\OrganizationAccessService;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class OrganizationInvitationController extends Controller
{
    public function __construct(private readonly OrganizationAccessService $access) {}

    public function mine(Request $request)
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return response()->json([]);
        }

        return response()->json(
            OrganizationInvitation::query()
                ->with('organization:id,name')
                ->whereRaw('LOWER(email) = ?', [strtolower($request->user()->email)])
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (OrganizationInvitation $invitation) => [
                    'id' => $invitation->id,
                    'organization_id' => $invitation->organization_id,
                    'organization_name' => $invitation->organization->name,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'expires_at' => $invitation->expires_at?->toISOString(),
                ])
        );
    }

    public function index(Request $request, Organization $organization)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'manage_users')) {
            return response()->json(['message' => 'You are not authorized to view company invitations.'], 403);
        }

        return response()->json(
            $organization->invitations()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get(['id', 'email', 'role', 'status', 'expires_at', 'created_at'])
        );
    }

    public function store(Request $request, Organization $organization)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'invite_users')) {
            return response()->json(['message' => 'You are not authorized to invite company users.'], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:organization_admin,organization_member'],
        ]);
        $email = strtolower($validated['email']);

        if ($actor->role !== 'organization_owner' && $validated['role'] !== 'organization_member') {
            return response()->json(['message' => 'Only the organization owner can invite administrators.'], 403);
        }

        $alreadyMember = $organization->memberships()
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
            ->exists();

        if ($alreadyMember) {
            return response()->json(['message' => 'This user is already an active company member.'], 409);
        }

        $pending = $organization->invitations()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->exists();

        if ($pending) {
            return response()->json(['message' => 'A pending invitation already exists for this email.'], 409);
        }

        $token = Str::random(64);
        $invitation = $organization->invitations()->create([
            'invited_by' => $request->user()->id,
            'email' => $email,
            'role' => $validated['role'],
            'status' => 'pending',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)->notify(
            new OrganizationInvitationNotification($organization->name, $invitation->id, $token, $invitation->role)
        );

        return response()->json([
            'message' => 'Invitation sent.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at?->toISOString(),
            ],
        ], 201);
    }

    public function accept(Request $request, OrganizationInvitation $invitation)
    {
        return $this->respond($request, $invitation, 'accepted');
    }

    public function reject(Request $request, OrganizationInvitation $invitation)
    {
        return $this->respond($request, $invitation, 'rejected');
    }

    public function revoke(Request $request, Organization $organization, OrganizationInvitation $invitation)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'manage_users')) {
            return response()->json(['message' => 'You are not authorized to manage company invitations.'], 403);
        }

        if ($invitation->organization_id !== $organization->id || $invitation->status !== 'pending') {
            return response()->json(['message' => 'Pending company invitation not found.'], 404);
        }

        $invitation->update(['status' => 'revoked']);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    private function respond(Request $request, OrganizationInvitation $invitation, string $status)
    {
        $user = $request->user();
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);

        if (! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Verify your email address before responding to an invitation.'], 403);
        }

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return response()->json(['message' => 'This invitation belongs to a different email address.'], 403);
        }

        if ($invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
            return response()->json(['message' => 'This invitation is no longer available.'], 409);
        }

        if (! hash_equals($invitation->token_hash, hash('sha256', $validated['token']))) {
            return response()->json(['message' => 'Invalid invitation token.'], 403);
        }

        DB::transaction(function () use ($invitation, $user, $status) {
            $lockedInvitation = OrganizationInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvitation->status !== 'pending' || $lockedInvitation->expires_at->isPast()) {
                abort(409, 'This invitation is no longer available.');
            }

            if ($status === 'accepted') {
                OrganizationMembership::query()->updateOrCreate(
                    [
                        'organization_id' => $lockedInvitation->organization_id,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => $lockedInvitation->role,
                        'status' => 'active',
                    ],
                );
            }

            $lockedInvitation->update([
                'status' => $status,
                'responded_by' => $user->id,
                'responded_at' => now(),
            ]);
        });

        return response()->json(['message' => $status === 'accepted' ? 'Invitation accepted.' : 'Invitation rejected.']);
    }
}
