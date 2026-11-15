<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectTrainingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_project_and_record_training_results(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Storage::disk('local')->put('datasets/retention_data.csv', "signal,churn\n1,0\n2,0\n3,1\n4,1\n");
        config(['services.ml.token' => 'feature-test-token']);
        Http::fakeSequence('127.0.0.1:8001/train')
            ->push([
                'status' => 'completed',
                'model_id' => 'test-model-id',
                'artifact_path' => 'test-model-id.joblib',
                'metrics' => ['accuracy' => 1.0],
                'source' => 'fastapi',
            ])
            ->push(['status' => 'queued']);

        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Vision Labs',
            'slug' => 'vision-labs',
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

        $projectResponse = $this->withToken($token)->postJson('/api/projects', [
            'organization_id' => $organization->id,
            'name' => 'Churn prediction',
            'description' => 'Customer retention forecasting model',
        ]);

        $projectResponse->assertStatus(201)
            ->assertJsonPath('project.name', 'Churn prediction')
            ->assertJsonPath('project.organization_id', $organization->id);

        $project = Project::query()->firstOrFail();

        $dataset = Dataset::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'retention_data',
            'file_path' => 'datasets/retention_data.csv',
            'status' => 'uploaded',
            'metadata' => [
                'row_count' => 120,
                'column_count' => 10,
            ],
        ]);

        $response = $this->withToken($token)->postJson("/api/projects/{$project->id}/train", [
            'dataset_id' => $dataset->id,
            'model_name' => 'random_forest',
            'target_column' => 'churn',
            'algorithm' => 'classification',
            'params' => ['n_estimators' => 200],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('job.project_id', $project->id)
            ->assertJsonPath('job.status', 'completed')
            ->assertJsonPath('job.model_name', 'random_forest')
            ->assertJsonPath('job.result.metrics.accuracy', 1);

        $this->assertDatabaseHas('ml_jobs', [
            'project_id' => $project->id,
            'dataset_id' => $dataset->id,
            'model_name' => 'random_forest',
            'status' => 'completed',
        ]);

        $unfinishedResponse = $this->withToken($token)->postJson("/api/projects/{$project->id}/train", [
            'dataset_id' => $dataset->id,
            'model_name' => 'random_forest',
            'target_column' => 'churn',
            'algorithm' => 'classification',
        ]);

        $unfinishedResponse->assertStatus(201)
            ->assertJsonPath('job.status', 'failed')
            ->assertJsonPath('job.result.message', 'ML service did not return a completed training result.');

        $jobId = $response->json('job.id');
        Http::assertSent(function ($request) use ($dataset, $jobId): bool {
            $body = $request->body();

            return $request->hasHeader('Authorization', 'Bearer feature-test-token')
                && str_contains($body, '"job_id":"'.$jobId.'"')
                && str_contains($body, '"dataset_id":"'.$dataset->id.'"')
                && str_contains($body, 'name="dataset_file"')
                && str_contains($body, 'filename="retention_data.csv"')
                && str_contains($body, 'name="metadata"')
                && str_contains($body, 'signal,churn');
        });
    }
}
