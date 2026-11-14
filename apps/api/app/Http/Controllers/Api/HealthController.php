<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'modelforge-api',
            'timestamp' => now()->toISOString(),
            'environment' => config('app.env'),
            'app_name' => config('app.name'),
            'tenant_mode' => 'multi-tenant',
        ]);
    }
}
