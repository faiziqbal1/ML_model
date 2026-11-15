<?php

namespace Tests\Feature;

use App\Models\MlJob;
use App\Models\Organization;
use App\Models\PredictionRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PredictionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_member_can_predict_and_retrieve_scoped_history_and_csv(): void
    {
        config(['services.ml.token' => 'prediction-test-token']);
        [$user, $organization, $model] = $this->makeModelWorkspace();
        $token = $user->createToken('test')->plainTextToken;
        Http::fake([
            '127.0.0.1:8001/models/*' => Http::response([
                'model_id' => $model->id,
                'feature_schema' => [['name' => 'signal', 'type' => 'number']],
                'supports_probabilities' => false,
            ]),
            '127.0.0.1:8001/predict' => Http::response([
                'model_id' => $model->id,
                'row_count' => 2,
                'predictions' => [
                    ['row' => 1, 'prediction' => 12.5, 'probabilities' => null],
                    ['row' => 2, 'prediction' => 21.5, 'probabilities' => null],
                ],
                'metadata' => [
                    'feature_schema' => [['name' => 'signal', 'type' => 'number']],
                    'supports_probabilities' => false,
                    'ignored_columns' => [],
                ],
            ]),
        ]);

        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/models")
            ->assertOk()
            ->assertJsonPath('models.0.id', $model->id)
            ->assertJsonMissingPath('models.0.artifact_path');

        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/models/{$model->id}")
            ->assertOk()
            ->assertJsonPath('model.features.0.name', 'signal');

        $response = $this->withToken($token)->post("/api/organizations/{$organization->id}/predictions", [
            'model_id' => $model->id,
            'name' => 'Morning batch',
            'file' => UploadedFile::fake()->createWithContent('input.csv', "signal\n5\n10\n"),
        ]);
        $response->assertCreated()
            ->assertJsonPath('prediction_run.status', 'completed')
            ->assertJsonPath('prediction_run.row_count', 2)
            ->assertJsonPath('results.predictions.0.prediction', 12.5);

        $runId = $response->json('prediction_run.id');
        $this->assertDatabaseHas('prediction_runs', [
            'id' => $runId,
            'organization_id' => $organization->id,
            'ml_job_id' => $model->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'row_count' => 2,
        ]);

        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/predictions")
            ->assertOk()
            ->assertJsonPath('prediction_runs.0.id', $runId)
            ->assertJsonPath('prediction_runs.0.input_filename', 'input.csv');

        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/predictions/{$runId}")
            ->assertOk()
            ->assertJsonPath('results.predictions.1.prediction', 21.5);

        $export = $this->withToken($token)
            ->get("/api/organizations/{$organization->id}/predictions/{$runId}/export");
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $export->streamedContent();
        $this->assertStringContainsString('row,prediction', $csv);
        $this->assertStringContainsString('1,12.5', $csv);

        Http::assertSent(fn ($request) => $request->hasHeader(
            'Authorization',
            'Bearer prediction-test-token',
        ) && str_contains($request->body(), 'name="input_file"')
            && str_contains($request->body(), 'signal'));
    }

    public function test_a_user_cannot_select_another_organizations_model_or_history(): void
    {
        [$user, $organization] = $this->makeModelWorkspace();
        [, $otherOrganization, $otherModel] = $this->makeModelWorkspace();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->post("/api/organizations/{$organization->id}/predictions", [
                'model_id' => $otherModel->id,
                'file' => UploadedFile::fake()->createWithContent('input.csv', "signal\n5\n"),
            ])
            ->assertNotFound();

        $otherRun = PredictionRun::query()->create([
            'organization_id' => $otherOrganization->id,
            'ml_job_id' => $otherModel->id,
            'user_id' => $user->id,
            'name' => 'Private run',
            'input_filename' => 'input.csv',
            'row_count' => 1,
            'status' => 'completed',
            'result' => ['predictions' => [['row' => 1, 'prediction' => 1, 'probabilities' => null]]],
            'completed_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/predictions/{$otherRun->id}")
            ->assertNotFound();
        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/predictions")
            ->assertOk()
            ->assertJsonCount(0, 'prediction_runs');

        $organization->memberships()->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/models")
            ->assertForbidden();
    }

    public function test_incomplete_models_and_invalid_csv_inputs_are_rejected(): void
    {
        [$user, $organization, $model, $project] = $this->makeModelWorkspace();
        $token = $user->createToken('test')->plainTextToken;
        $incomplete = MlJob::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'job_type' => 'training',
            'status' => 'failed',
            'model_name' => 'failed-model',
            'target_column' => 'target',
            'result' => ['status' => 'failed'],
        ]);

        $this->withToken($token)
            ->getJson("/api/organizations/{$organization->id}/models")
            ->assertOk()
            ->assertJsonCount(1, 'models')
            ->assertJsonMissing(['id' => $incomplete->id]);

        $this->withToken($token)
            ->post("/api/organizations/{$organization->id}/predictions", [
                'model_id' => $incomplete->id,
                'file' => UploadedFile::fake()->createWithContent('input.csv', "signal\n1\n"),
            ])
            ->assertUnprocessable();

        $this->withToken($token)
            ->post("/api/organizations/{$organization->id}/predictions", [
                'model_id' => $model->id,
                'file' => UploadedFile::fake()->createWithContent('input.xlsx', 'not csv'),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('prediction_runs', 0);
    }

    public function test_ml_validation_failure_is_saved_as_a_failed_run(): void
    {
        config(['services.ml.token' => 'prediction-test-token']);
        [$user, $organization, $model] = $this->makeModelWorkspace();
        $token = $user->createToken('test')->plainTextToken;
        Http::fake([
            '127.0.0.1:8001/predict' => Http::response([
                'detail' => 'Missing required feature columns: signal.',
            ], 422),
        ]);

        $response = $this->withToken($token)->post("/api/organizations/{$organization->id}/predictions", [
            'model_id' => $model->id,
            'file' => UploadedFile::fake()->createWithContent('input.csv', "wrong_column\n1\n"),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('prediction_run.status', 'failed')
            ->assertJsonPath('prediction_run.error_message', 'Missing required feature columns: signal.');
        $this->assertDatabaseHas('prediction_runs', [
            'organization_id' => $organization->id,
            'status' => 'failed',
            'error_message' => 'Missing required feature columns: signal.',
        ]);
    }

    private function makeModelWorkspace(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Prediction Labs',
            'slug' => 'prediction-labs-'.uniqid(),
            'status' => 'active',
            'registration_policy' => 'open',
            'trial_ends_at' => now()->addDays(14),
            'settings' => [],
        ]);
        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => 'organization_member',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Inference project',
            'status' => 'active',
        ]);
        $model = MlJob::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'job_type' => 'training',
            'status' => 'completed',
            'model_name' => 'random_forest',
            'target_column' => 'target',
            'params' => ['algorithm' => 'regression'],
            'result' => [],
            'finished_at' => now(),
        ]);
        $model->update([
            'result' => [
                'status' => 'completed',
                'model_id' => $model->id,
                'artifact_path' => $model->id.'.joblib',
                'estimator' => 'random_forest',
                'problem_type' => 'regression',
                'metrics' => ['r2' => 0.9],
            ],
        ]);

        return [$user, $organization, $model, $project];
    }
}
