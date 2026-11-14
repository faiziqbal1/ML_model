<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $organizations = Organization::query()
            ->withCount('projects')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id))
            ->get()
            ->map(fn ($organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'status' => $organization->status,
                'project_count' => $organization->projects_count,
            ]);

        return response()->json($organizations);
    }
}
