<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationAccessService
{
    private const PERMISSIONS = [
        'organization_owner' => [
            'view_company_profile',
            'edit_company_profile',
            'view_users',
            'invite_users',
            'manage_users',
            'assign_roles',
            'manage_company_settings',
            'manage_projects',
            'view_datasets',
            'upload_datasets',
            'train_models',
            'view_models',
            'run_predictions',
            'view_prediction_history',
        ],
        'organization_admin' => [
            'view_company_profile',
            'edit_company_profile',
            'view_users',
            'invite_users',
            'manage_users',
            'assign_roles',
            'manage_projects',
            'view_datasets',
            'upload_datasets',
            'train_models',
            'view_models',
            'run_predictions',
            'view_prediction_history',
        ],
        'organization_member' => [
            'view_company_profile',
            'view_users',
            'view_datasets',
            'view_models',
            'run_predictions',
            'view_prediction_history',
        ],
    ];

    public function membership(User $user, Organization $organization): ?OrganizationMembership
    {
        return $organization->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    public function allows(OrganizationMembership $membership, string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS[$membership->role] ?? [], true);
    }

    public function permissionsFor(string $role): array
    {
        return self::PERMISSIONS[$role] ?? [];
    }

    public function roleDefinitions(): array
    {
        return [
            ['role' => 'organization_owner', 'permissions' => $this->permissionsFor('organization_owner')],
            ['role' => 'organization_admin', 'permissions' => $this->permissionsFor('organization_admin')],
            ['role' => 'organization_member', 'permissions' => $this->permissionsFor('organization_member')],
        ];
    }
}
