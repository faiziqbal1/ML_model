<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $featureDefinitions = [
            ['key' => 'basic_mode', 'name' => 'Basic mode', 'type' => 'boolean'],
            ['key' => 'advanced_mode', 'name' => 'Advanced mode', 'type' => 'boolean'],
            ['key' => 'bulk_predictions', 'name' => 'Bulk predictions', 'type' => 'quota'],
            ['key' => 'random_forest', 'name' => 'Random forest', 'type' => 'boolean'],
            ['key' => 'gradient_boosting', 'name' => 'Gradient boosting', 'type' => 'boolean'],
            ['key' => 'hyperparameter_tuning', 'name' => 'Hyperparameter tuning', 'type' => 'boolean'],
            ['key' => 'model_comparison', 'name' => 'Model comparison', 'type' => 'boolean'],
            ['key' => 'advanced_metrics', 'name' => 'Advanced metrics', 'type' => 'boolean'],
            ['key' => 'priority_processing', 'name' => 'Priority processing', 'type' => 'boolean'],
        ];

        foreach ($featureDefinitions as $featureDefinition) {
            Feature::query()->firstOrCreate(
                ['key' => $featureDefinition['key']],
                [
                    'name' => $featureDefinition['name'],
                    'description' => $featureDefinition['name'],
                    'type' => $featureDefinition['type'],
                    'is_active' => true,
                ],
            );
        }

        $freePlan = SubscriptionPlan::query()->firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Basic access for evaluation and prototypes.',
                'billing_cycle' => 'free',
                'status' => 'active',
                'price' => 0,
                'trial_days' => 7,
                'is_default' => true,
            ],
        );

        $starterPlan = SubscriptionPlan::query()->firstOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter',
                'description' => 'For individuals and small teams.',
                'billing_cycle' => 'monthly',
                'status' => 'active',
                'price' => 39,
                'trial_days' => 14,
                'is_default' => false,
            ],
        );

        $planFeatures = [
            ['plan' => $freePlan, 'key' => 'basic_mode', 'enabled' => true, 'value' => ['limit' => 0]],
            ['plan' => $freePlan, 'key' => 'bulk_predictions', 'enabled' => true, 'value' => ['limit' => 25]],
            ['plan' => $starterPlan, 'key' => 'basic_mode', 'enabled' => true, 'value' => ['limit' => 0]],
            ['plan' => $starterPlan, 'key' => 'advanced_mode', 'enabled' => true, 'value' => ['limit' => 0]],
            ['plan' => $starterPlan, 'key' => 'bulk_predictions', 'enabled' => true, 'value' => ['limit' => 500]],
            ['plan' => $starterPlan, 'key' => 'random_forest', 'enabled' => true, 'value' => ['limit' => 0]],
            ['plan' => $starterPlan, 'key' => 'gradient_boosting', 'enabled' => true, 'value' => ['limit' => 0]],
            ['plan' => $starterPlan, 'key' => 'model_comparison', 'enabled' => true, 'value' => ['limit' => 0]],
        ];

        foreach ($planFeatures as $planFeature) {
            $feature = Feature::query()->where('key', $planFeature['key'])->first();

            if (! $feature) {
                continue;
            }

            PlanFeature::query()->updateOrCreate(
                [
                    'plan_id' => $planFeature['plan']->id,
                    'feature_id' => $feature->id,
                ],
                [
                    'enabled' => $planFeature['enabled'],
                    'value' => $planFeature['value'],
                ],
            );
        }
    }
}
