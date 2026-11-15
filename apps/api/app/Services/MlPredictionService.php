<?php

namespace App\Services;

use App\Exceptions\MlPredictionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class MlPredictionService
{
    public function modelMetadata(string $modelId): array
    {
        try {
            $response = $this->client()->get($this->baseUrl().'/models/'.$modelId);
        } catch (ConnectionException) {
            throw new MlPredictionException('Unable to connect to the ML service.');
        }

        $data = $this->decodeResponse($response);
        if (
            ($data['model_id'] ?? null) !== $modelId
            || ! is_array($data['feature_schema'] ?? null)
        ) {
            throw new MlPredictionException('ML service returned invalid model metadata.');
        }

        return $data;
    }

    public function predict(string $modelId, UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents)) {
            throw new MlPredictionException('The prediction CSV could not be read.', 422);
        }

        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));

        try {
            $response = $this->client()
                ->attach('input_file', $contents, $filename)
                ->post($this->baseUrl().'/predict', ['model_id' => $modelId]);
        } catch (ConnectionException) {
            throw new MlPredictionException('Unable to connect to the ML service.');
        }

        $data = $this->decodeResponse($response);
        if (
            ($data['model_id'] ?? null) !== $modelId
            || ! is_int($data['row_count'] ?? null)
            || ! is_array($data['predictions'] ?? null)
            || count($data['predictions']) !== $data['row_count']
            || ! is_array($data['metadata'] ?? null)
        ) {
            throw new MlPredictionException('ML service returned invalid prediction results.');
        }

        return $data;
    }

    private function client(): PendingRequest
    {
        $token = config('services.ml.token');
        if (! is_string($token) || $token === '') {
            throw new MlPredictionException('ML service authentication is not configured.', 503);
        }

        $timeout = (int) config(
            'services.ml.prediction_timeout_seconds',
            config('services.ml.timeout_seconds', 600),
        );

        return Http::connectTimeout(10)
            ->timeout($timeout)
            ->acceptJson()
            ->withToken($token);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.ml.url'), '/');
    }

    private function decodeResponse(Response $response): array
    {
        $data = $response->json();
        if (! $response->successful()) {
            $detail = is_array($data) ? data_get($data, 'detail') : null;
            $message = is_string($detail) ? $detail : 'ML service rejected the prediction request.';
            $status = match ($response->status()) {
                404, 413, 422 => $response->status(),
                default => 502,
            };

            throw new MlPredictionException($message, $status);
        }

        if (! is_array($data)) {
            throw new MlPredictionException('ML service returned an invalid response.');
        }

        return $data;
    }
}
