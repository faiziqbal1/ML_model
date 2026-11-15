<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MlPredictionException;
use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Organization;
use App\Models\PredictionRun;
use App\Services\MlPredictionService;
use App\Services\OrganizationAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PredictionController extends Controller
{
    public function __construct(
        private readonly MlPredictionService $predictionService,
        private readonly OrganizationAccessService $access,
    ) {}

    public function models(Request $request, Organization $organization)
    {
        $this->authorizeOrganization($request, $organization, 'view_models');

        $models = MlJob::query()
            ->where('organization_id', $organization->id)
            ->where('job_type', 'training')
            ->where('status', 'completed')
            ->orderByDesc('finished_at')
            ->get()
            ->filter(fn (MlJob $job) => $this->isUsableModel($job))
            ->map(fn (MlJob $job) => $this->modelSummary($job))
            ->values();

        return response()->json(['models' => $models]);
    }

    public function model(Request $request, Organization $organization, string $modelId)
    {
        $this->authorizeOrganization($request, $organization, 'view_models');
        $model = $this->findModel($organization, $modelId);
        if (! $model) {
            return response()->json(['message' => 'Model not found.'], 404);
        }
        if (! $this->isUsableModel($model)) {
            return response()->json(['message' => 'This model is not completed or usable.'], 422);
        }

        try {
            $metadata = $this->predictionService->modelMetadata($model->id);
        } catch (MlPredictionException $error) {
            return response()->json(['message' => $error->getMessage()], $error->httpStatus);
        }

        if (! $this->isValidFeatureSchema($metadata['feature_schema'] ?? null)) {
            return response()->json(['message' => 'ML service returned an invalid feature schema.'], 502);
        }

        return response()->json([
            'model' => [
                ...$this->modelSummary($model),
                'features' => $metadata['feature_schema'],
                'supports_probabilities' => (bool) ($metadata['supports_probabilities'] ?? false),
            ],
        ]);
    }

    public function history(Request $request, Organization $organization)
    {
        $this->authorizeOrganization($request, $organization, 'view_prediction_history');

        $runs = PredictionRun::query()
            ->where('organization_id', $organization->id)
            ->with('mlJob')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PredictionRun $run) => $this->runSummary($run));

        return response()->json(['prediction_runs' => $runs]);
    }

    public function store(Request $request, Organization $organization)
    {
        $this->authorizeOrganization($request, $organization, 'run_predictions');
        $validated = $request->validate([
            'model_id' => ['required', 'uuid'],
            'name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
            return response()->json(['message' => 'Prediction input must be a CSV file.'], 422);
        }

        $model = $this->findModel($organization, $validated['model_id']);
        if (! $model) {
            return response()->json(['message' => 'Model not found.'], 404);
        }
        if (! $this->isUsableModel($model)) {
            return response()->json(['message' => 'This model is not completed or usable.'], 422);
        }

        $run = PredictionRun::query()->create([
            'organization_id' => $organization->id,
            'ml_job_id' => $model->id,
            'user_id' => $request->user()->id,
            'name' => ($validated['name'] ?? null) ?: pathinfo($filename, PATHINFO_FILENAME),
            'input_filename' => $filename,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $prediction = $this->predictionService->predict($model->id, $file);
            $featureSchema = $prediction['metadata']['feature_schema'] ?? null;
            if (! $this->isValidFeatureSchema($featureSchema)) {
                throw new MlPredictionException('ML service returned an invalid feature schema.');
            }

            $run->update([
                'row_count' => $prediction['row_count'],
                'status' => 'completed',
                'result' => [
                    'predictions' => $prediction['predictions'],
                    'metadata' => [
                        'model_id' => $model->id,
                        'algorithm' => $model->result['estimator'],
                        'problem_type' => $model->result['problem_type'],
                        'target_column' => $model->target_column,
                        'feature_schema' => $featureSchema,
                        'supports_probabilities' => (bool) ($prediction['metadata']['supports_probabilities'] ?? false),
                        'ignored_columns' => $prediction['metadata']['ignored_columns'] ?? [],
                    ],
                ],
                'completed_at' => now(),
            ]);
        } catch (MlPredictionException $error) {
            $run->update([
                'status' => 'failed',
                'error_message' => $error->getMessage(),
                'completed_at' => now(),
            ]);

            return response()->json([
                'message' => $error->getMessage(),
                'prediction_run' => $this->runSummary($run->fresh()),
            ], $error->httpStatus);
        }

        return response()->json([
            'message' => 'Prediction completed.',
            'prediction_run' => $this->runSummary($run->fresh()),
            'results' => $run->fresh()->result,
        ], 201);
    }

    public function show(Request $request, Organization $organization, string $predictionId)
    {
        $this->authorizeOrganization($request, $organization, 'view_prediction_history');
        $run = PredictionRun::query()
            ->where('organization_id', $organization->id)
            ->with('mlJob')
            ->findOrFail($predictionId);

        return response()->json([
            'prediction_run' => $this->runSummary($run),
            'results' => $run->result,
        ]);
    }

    public function export(Request $request, Organization $organization, string $predictionId)
    {
        $this->authorizeOrganization($request, $organization, 'view_prediction_history');
        $run = PredictionRun::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($predictionId);
        if ($run->status !== 'completed' || ! is_array($run->result['predictions'] ?? null)) {
            return response()->json(['message' => 'Only completed prediction runs can be exported.'], 422);
        }

        $predictions = $run->result['predictions'];
        $classes = [];
        foreach ($predictions as $prediction) {
            foreach ($prediction['probabilities'] ?? [] as $probability) {
                $class = (string) ($probability['class'] ?? '');
                if (! in_array($class, $classes, true)) {
                    $classes[] = $class;
                }
            }
        }

        return response()->streamDownload(static function () use ($predictions, $classes): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['row', 'prediction', ...array_map(
                static fn (string $class) => 'probability_'.$class,
                $classes,
            )]);

            foreach ($predictions as $prediction) {
                $probabilities = [];
                foreach ($prediction['probabilities'] ?? [] as $item) {
                    $probabilities[(string) ($item['class'] ?? '')] = $item['probability'] ?? '';
                }
                fputcsv($output, [
                    $prediction['row'] ?? '',
                    $prediction['prediction'] ?? '',
                    ...array_map(static fn (string $class) => $probabilities[$class] ?? '', $classes),
                ]);
            }

            fclose($output);
        }, Str::uuid().'-predictions.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeOrganization(Request $request, Organization $organization, string $permission): void
    {
        $membership = $this->access->membership($request->user(), $organization);
        abort_unless(
            $membership && $this->access->allows($membership, $permission),
            403,
            'You are not authorized to access predictions for this organization.',
        );
    }

    private function findModel(Organization $organization, string $modelId): ?MlJob
    {
        return MlJob::query()
            ->where('organization_id', $organization->id)
            ->where('job_type', 'training')
            ->whereKey($modelId)
            ->first();
    }

    private function isUsableModel(MlJob $model): bool
    {
        $result = $model->result;

        return $model->status === 'completed'
            && is_array($result)
            && ($result['status'] ?? null) === 'completed'
            && ($result['model_id'] ?? null) === $model->id
            && ($result['artifact_path'] ?? null) === $model->id.'.joblib'
            && in_array($result['problem_type'] ?? null, ['regression', 'classification'], true)
            && is_string($result['estimator'] ?? null)
            && is_string($model->target_column);
    }

    private function isValidFeatureSchema(mixed $schema): bool
    {
        if (! is_array($schema) || $schema === []) {
            return false;
        }

        foreach ($schema as $feature) {
            if (
                ! is_array($feature)
                || ! is_string($feature['name'] ?? null)
                || ! in_array($feature['type'] ?? null, ['number', 'category'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    private function modelSummary(MlJob $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->model_name,
            'algorithm' => $model->result['estimator'],
            'problem_type' => $model->result['problem_type'],
            'target_column' => $model->target_column,
            'metrics' => $model->result['metrics'] ?? [],
            'trained_at' => $model->finished_at?->toISOString(),
        ];
    }

    private function runSummary(PredictionRun $run): array
    {
        return [
            'id' => $run->id,
            'name' => $run->name,
            'model_id' => $run->ml_job_id,
            'model_name' => $run->mlJob?->model_name,
            'input_filename' => $run->input_filename,
            'row_count' => $run->row_count,
            'status' => $run->status,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
        ];
    }
}
