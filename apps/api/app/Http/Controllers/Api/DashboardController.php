<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $organizationIds = $request->user()->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');
        $organizationCount = $organizationIds->count();
        $projectCount = Project::query()->whereIn('organization_id', $organizationIds)->count();

        return response()->json([
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'summary' => [
                'organization_count' => $organizationCount,
                'project_count' => $projectCount,
                'subscription_mode' => 'trial',
                'ml_jobs_active' => 0,
            ],
        ]);
    }
}
