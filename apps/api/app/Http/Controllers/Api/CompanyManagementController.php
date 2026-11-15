<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\OrganizationAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyManagementController extends Controller
{
    public function __construct(private readonly OrganizationAccessService $access) {}

    public function updateProfile(Request $request, Organization $organization)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'edit_company_profile')) {
            return response()->json(['message' => 'You are not authorized to edit this company profile.'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'industry' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'timezone'],
        ]);

        $organization->fill($validated)->save();

        return response()->json(['organization' => $this->profileData($organization, $actor)]);
    }

    public function users(Request $request, Organization $organization)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'view_users')) {
            return response()->json(['message' => 'You are not authorized to view company users.'], 403);
        }

        return response()->json(
            $organization->memberships()
                ->with('user:id,name,email')
                ->orderBy('created_at')
                ->get()
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role,
                    'status' => $membership->status,
                    'joined_at' => $membership->created_at?->toISOString(),
                ])
        );
    }

    public function updateMembership(Request $request, Organization $organization, OrganizationMembership $membership)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'manage_users')) {
            return response()->json(['message' => 'You are not authorized to manage company users.'], 403);
        }

        if ($membership->organization_id !== $organization->id || $membership->status !== 'active') {
            return response()->json(['message' => 'Active company membership not found.'], 404);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:organization_owner,organization_admin,organization_member'],
        ]);

        if ($membership->id === $actor->id && $validated['role'] !== $actor->role) {
            return response()->json(['message' => 'You cannot change your own company role.'], 403);
        }

        if ($validated['role'] === 'organization_owner') {
            if ($actor->role !== 'organization_owner' || $membership->role === 'organization_owner') {
                return response()->json(['message' => 'Only the current owner can transfer ownership to another member.'], 403);
            }

            DB::transaction(function () use ($organization, $membership) {
                $organization->memberships()
                    ->where('status', 'active')
                    ->where('role', 'organization_owner')
                    ->update(['role' => 'organization_admin']);
                $membership->update(['role' => 'organization_owner']);
            });
        } elseif ($actor->role !== 'organization_owner' && $validated['role'] !== 'organization_member') {
            return response()->json(['message' => 'Only the organization owner can assign administrator roles.'], 403);
        } elseif ($membership->role === 'organization_owner') {
            return response()->json(['message' => 'The organization owner role cannot be changed here.'], 403);
        } else {
            $membership->update(['role' => $validated['role']]);
        }

        return response()->json(['message' => 'Company role updated.']);
    }

    public function revokeMembership(Request $request, Organization $organization, OrganizationMembership $membership)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'manage_users')) {
            return response()->json(['message' => 'You are not authorized to manage company users.'], 403);
        }

        if ($membership->organization_id !== $organization->id || $membership->status !== 'active') {
            return response()->json(['message' => 'Active company membership not found.'], 404);
        }

        if ($membership->role === 'organization_owner') {
            return response()->json(['message' => 'The organization owner membership cannot be revoked here.'], 403);
        }

        if ($actor->role !== 'organization_owner' && $membership->role !== 'organization_member') {
            return response()->json(['message' => 'Administrators cannot revoke another administrator.'], 403);
        }

        $membership->update(['status' => 'revoked']);

        return response()->json(['message' => 'Company access revoked.']);
    }

    public function permissions(Request $request, Organization $organization)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json([
            'current_role' => $actor->role,
            'current_permissions' => $this->access->permissionsFor($actor->role),
            'roles' => $this->access->roleDefinitions(),
        ]);
    }

    public function updateSettings(Request $request, Organization $organization)
    {
        $actor = $this->access->membership($request->user(), $organization);

        if (! $actor || ! $this->access->allows($actor, 'manage_company_settings')) {
            return response()->json(['message' => 'Only the organization owner can manage company settings.'], 403);
        }

        $validated = $request->validate([
            'registration_policy' => ['required', 'in:open,admin_approval,invite_only'],
        ]);
        $organization->update($validated);

        return response()->json([
            'message' => 'Company settings updated.',
            'registration_policy' => $organization->registration_policy,
        ]);
    }

    private function profileData(Organization $organization, OrganizationMembership $membership): array
    {
        return [
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
        ];
    }
}
