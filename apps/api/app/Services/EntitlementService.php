<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Organization;
use App\Models\PlanFeature;
use App\Models\UsageEvent;

class EntitlementService
{
    public function hasFeature(Organization $organization, string $featureKey): bool
    {
        $feature = Feature::query()->where('key', $featureKey)->where('is_active', true)->first();

        if (! $feature) {
            return false;
        }

        $featureOverrides = $organization->settings['feature_overrides'] ?? [];

        if (array_key_exists($featureKey, $featureOverrides)) {
            return (bool) $featureOverrides[$featureKey];
        }

        $activeSubscription = $organization->subscriptions()
            ->whereIn('status', ['trial', 'active'])
            ->orderByDesc('started_at')
            ->first();

        if (! $activeSubscription) {
            return false;
        }

        $planFeature = PlanFeature::query()
            ->where('plan_id', $activeSubscription->plan_id)
            ->whereHas('feature', fn ($query) => $query->where('key', $featureKey))
            ->first();

        if (! $planFeature) {
            return false;
        }

        return (bool) $planFeature->enabled;
    }

    public function getFeatureConfig(Organization $organization, string $featureKey): array
    {
        $featureOverrides = $organization->settings['feature_overrides'] ?? [];

        if (array_key_exists($featureKey, $featureOverrides)) {
            return $featureOverrides[$featureKey];
        }

        $activeSubscription = $organization->subscriptions()
            ->whereIn('status', ['trial', 'active'])
            ->orderByDesc('started_at')
            ->first();

        if (! $activeSubscription) {
            return ['enabled' => false, 'limit' => 0];
        }

        $planFeature = PlanFeature::query()
            ->where('plan_id', $activeSubscription->plan_id)
            ->whereHas('feature', fn ($query) => $query->where('key', $featureKey))
            ->first();

        if (! $planFeature) {
            return ['enabled' => false, 'limit' => 0];
        }

        return [
            'enabled' => (bool) $planFeature->enabled,
            'limit' => (int) ($planFeature->value['limit'] ?? 0),
        ];
    }

    public function canUseQuota(Organization $organization, string $featureKey, int $requestedAmount = 1): bool
    {
        $config = $this->getFeatureConfig($organization, $featureKey);

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        $limit = (int) ($config['limit'] ?? 0);

        if ($limit <= 0) {
            return true;
        }

        $used = UsageEvent::query()
            ->where('organization_id', $organization->id)
            ->where('feature_key', $featureKey)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('quantity');

        return ((int) $used + $requestedAmount) <= $limit;
    }

    public function recordUsage(Organization $organization, string $featureKey, int $quantity = 1, array $metadata = []): UsageEvent
    {
        return UsageEvent::query()->create([
            'organization_id' => $organization->id,
            'user_id' => auth()->id(),
            'feature_key' => $featureKey,
            'quantity' => $quantity,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function getUsageSummary(Organization $organization): array
    {
        $events = UsageEvent::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get();

        $summary = [];

        foreach ($events as $event) {
            $summary[$event->feature_key] = ($summary[$event->feature_key] ?? 0) + (int) $event->quantity;
        }

        return $summary;
    }
}
