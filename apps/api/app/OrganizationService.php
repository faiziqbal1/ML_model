<?php

namespace App;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

class OrganizationService
{
    public function createForUser(User $user, array $attributes): Organization
    {
        $slug = $attributes['slug'] ?? Str::slug($attributes['name']);
        $baseSlug = $slug;
        $counter = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = sprintf('%s-%d', $baseSlug, $counter);
            $counter++;
        }

        $organization = Organization::create([
            'name' => $attributes['name'],
            'slug' => $slug,
            'status' => $attributes['status'] ?? 'active',
            'registration_policy' => $attributes['registration_policy'] ?? 'open',
            'trial_ends_at' => $attributes['trial_ends_at'] ?? now()->addDays(14),
            'settings' => $attributes['settings'] ?? [],
        ]);

        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => 'organization_owner',
            'status' => 'active',
        ]);

        return $organization->load('memberships.user');
    }
}
