<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DatasetUploadAndAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_and_analyze_dataset(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Dataset Org',
            'slug' => 'dataset-org',
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

        $token = $user->createToken('test')->plainTextToken;
        $file = UploadedFile::fake()->createWithContent('students.csv', "name,age,score\nAlice,20,80\nBob,22,90\nAlice,20,80\n");

        $response = $this->withToken($token)->postJson('/api/datasets', [
            'organization_id' => $organization->id,
            'name' => 'Students',
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('dataset.name', 'Students')
            ->assertJsonPath('dataset.metadata.row_count', 3)
            ->assertJsonPath('dataset.metadata.column_count', 3);

        $this->assertDatabaseHas('datasets', [
            'organization_id' => $organization->id,
            'name' => 'Students',
        ]);
    }
}
