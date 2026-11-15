<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Models\Organization;
use App\Models\Project;
use App\Services\MlJobService;
use App\Services\OrganizationAccessService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private readonly MlJobService $mlJobService,
        private readonly OrganizationAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $organizationIds = $request->user()->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');

        $projects = Project::query()
            ->whereIn('organization_id', $organizationIds)
            ->withCount('datasets')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($projects->map(fn (Project $project) => [
            'id' => $project->id,
            'organization_id' => $project->organization_id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'dataset_count' => $project->datasets_count,
            'created_at' => $project->created_at?->toISOString(),
        ]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $organization = Organization::query()->findOrFail($validated['organization_id']);
        $membership = $this->access->membership($request->user(), $organization);

        if (! $membership || ! $this->access->allows($membership, 'manage_projects')) {
            return response()->json(['message' => 'You are not authorized to create projects.'], 403);
        }

        $project = Project::query()->create([
            'organization_id' => $validated['organization_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => 'draft',
        ]);

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => [
                'id' => $project->id,
                'organization_id' => $project->organization_id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
            ],
        ], 201);
    }

    public function show(Request $request, Project $project)
    {
        if (! $request->user()->organizationMemberships()->where('organization_id', $project->organization_id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json([
            'id' => $project->id,
            'organization_id' => $project->organization_id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'dataset_count' => $project->datasets()->count(),
        ]);
    }

    public function jobs(Request $request, Project $project)
    {
        if (! $request->user()->organizationMemberships()->where('organization_id', $project->organization_id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json(
            $project->jobs()->orderByDesc('created_at')->get()->map(fn ($job) => [
                'id' => $job->id,
                'status' => $job->status,
                'job_type' => $job->job_type,
                'model_name' => $job->model_name,
                'target_column' => $job->target_column,
                'result' => $job->result,
                'created_at' => $job->created_at?->toISOString(),
            ])
        );
    }

    public function train(Request $request, Project $project)
    {
        $membership = $this->access->membership($request->user(), $project->organization);

        if (! $membership || ! $this->access->allows($membership, 'train_models')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'dataset_id' => ['required', 'exists:datasets,id'],
            'model_name' => ['required', 'string', 'max:255'],
            'target_column' => ['required', 'string', 'max:255'],
            'algorithm' => ['nullable', 'string', 'max:255'],
            'params' => ['nullable', 'array'],
        ]);

        $dataset = Dataset::query()
            ->where('organization_id', $project->organization_id)
            ->findOrFail($validated['dataset_id']);

        $job = $this->mlJobService->submit(
            $project,
            $dataset,
            $validated['model_name'],
            $validated['target_column'],
            $validated['params'] ?? [],
            $validated['algorithm'] ?? null,
        );

        return response()->json([
            'message' => 'Training job queued successfully.',
            'job' => [
                'id' => $job->id,
                'project_id' => $job->project_id,
                'dataset_id' => $job->dataset_id,
                'status' => $job->status,
                'model_name' => $job->model_name,
                'target_column' => $job->target_column,
                'result' => $job->result,
            ],
        ], 201);
    }
}
