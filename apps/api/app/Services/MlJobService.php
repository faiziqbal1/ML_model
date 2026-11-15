<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\MlJob;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MlJobService
{
    public function submit(
        Project $project,
        Dataset $dataset,
        string $modelName,
        string $targetColumn,
        array $params = [],
        ?string $algorithm = null,
    ): MlJob {
        $job = MlJob::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'dataset_id' => $dataset->id,
            'job_type' => 'training',
            'status' => 'queued',
            'model_name' => $modelName,
            'target_column' => $targetColumn,
            'params' => [
                'algorithm' => $algorithm ?? 'classification',
                ...$params,
            ],
            'result' => ['source' => 'laravel'],
            'queued_at' => now(),
        ]);

        $datasetPath = $dataset->file_path;
        $disk = Storage::disk('local');
        if (
            ! is_string($datasetPath)
            || ! str_starts_with($datasetPath, 'datasets/')
            || basename($datasetPath) !== substr($datasetPath, strlen('datasets/'))
            || ! $disk->exists($datasetPath)
        ) {
            return $this->fail($job, 'The private dataset file could not be found.');
        }

        $token = config('services.ml.token');
        if (! is_string($token) || $token === '') {
            return $this->fail($job, 'ML service authentication is not configured.');
        }

        $payload = [
            'job_id' => $job->id,
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'dataset_id' => $dataset->id,
            'dataset_name' => $dataset->name,
            'model_name' => $modelName,
            'target_column' => $targetColumn,
            'algorithm' => $algorithm ?? 'classification',
            'params' => $params,
        ];

        $job->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $baseUrl = rtrim((string) config('services.ml.url'), '/');
            $response = Http::connectTimeout(10)
                ->timeout((int) config('services.ml.timeout_seconds', 600))
                ->withToken($token)
                ->attach(
                    'dataset_file',
                    $disk->get($datasetPath),
                    basename($datasetPath),
                )
                ->post($baseUrl.'/train', [
                    'metadata' => json_encode($payload, JSON_THROW_ON_ERROR),
                ]);

            if (! $response->successful()) {
                $responseDetail = data_get($response->json(), 'detail');
                $message = is_string($responseDetail)
                    ? $responseDetail
                    : 'ML service rejected the training request.';

                return $this->fail($job, $message, [
                    'source' => 'fastapi',
                    'http_status' => $response->status(),
                ]);
            }

            $data = $response->json();
            if (
                ! is_array($data)
                || ($data['status'] ?? null) !== 'completed'
                || ! is_string($data['model_id'] ?? null)
                || ! is_string($data['artifact_path'] ?? null)
                || ! is_array($data['metrics'] ?? null)
            ) {
                return $this->fail($job, 'ML service did not return a completed training result.', [
                    'source' => 'fastapi',
                ]);
            }

            $job->update([
                'status' => 'completed',
                'result' => $data,
                'finished_at' => now(),
            ]);
        } catch (ConnectionException) {
            return $this->fail(
                $job,
                'Unable to connect to the ML service or training timed out.',
            );
        }

        return $job->fresh();
    }

    private function fail(MlJob $job, string $message, array $details = []): MlJob
    {
        $job->update([
            'status' => 'failed',
            'error_message' => $message,
            'result' => [
                'source' => $details['source'] ?? 'laravel',
                'message' => $message,
                ...$details,
            ],
            'finished_at' => now(),
        ]);

        return $job->fresh();
    }
}
