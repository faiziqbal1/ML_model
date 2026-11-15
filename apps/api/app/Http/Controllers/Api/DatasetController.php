<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDatasetRequest;
use App\Models\Dataset;
use App\Models\Organization;
use App\Services\DatasetAnalysisService;
use App\Services\OrganizationAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DatasetController extends Controller
{
    public function __construct(
        private readonly DatasetAnalysisService $datasetAnalysisService,
        private readonly OrganizationAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $organizationIds = $request->user()->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');

        $datasets = Dataset::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($datasets->map(fn ($dataset) => [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'status' => $dataset->status,
            'organization_id' => $dataset->organization_id,
            'project_id' => $dataset->project_id,
            'metadata' => $dataset->metadata,
            'updated_at' => $dataset->updated_at?->toISOString(),
        ]));
    }

    public function show(Request $request, Dataset $dataset)
    {
        if ($dataset->organization_id !== null && ! $request->user()->organizationMemberships()->where('organization_id', $dataset->organization_id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json([
            'id' => $dataset->id,
            'name' => $dataset->name,
            'status' => $dataset->status,
            'organization_id' => $dataset->organization_id,
            'project_id' => $dataset->project_id,
            'metadata' => $dataset->metadata,
        ]);
    }

    public function store(StoreDatasetRequest $request)
    {
        $organization = Organization::query()->findOrFail($request->input('organization_id'));

        $membership = $this->access->membership($request->user(), $organization);

        if (! $membership || ! $this->access->allows($membership, 'upload_datasets')) {
            return response()->json(['message' => 'You are not authorized to upload datasets.'], 403);
        }

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $savedFilename = $this->makeUniqueFilename($originalName);
        $savedPath = Storage::disk('local')->putFileAs('datasets', $file, $savedFilename);

        $analysis = $this->datasetAnalysisService->analyze(Storage::disk('local')->path($savedPath));

        $dataset = Dataset::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $request->input('project_id'),
            'name' => $request->input('name') ?? pathinfo($originalName, PATHINFO_FILENAME),
            'file_path' => $savedPath,
            'status' => 'uploaded',
            'metadata' => $analysis,
        ]);

        return response()->json([
            'message' => 'Dataset uploaded and analyzed successfully.',
            'dataset' => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'status' => $dataset->status,
                'file_path' => $dataset->file_path,
                'metadata' => $dataset->metadata,
            ],
        ], 201);
    }

    private function makeUniqueFilename(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        return sprintf('%s-%s.%s', strtolower(str_replace([' ', '/'], '-', $baseName)), now()->format('YmdHis'), strtolower($extension));
    }
}
