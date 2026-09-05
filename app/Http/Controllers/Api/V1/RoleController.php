<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * GET /v1/roles — global catalog of business roles (R11.1).
     *
     * Roles are global definitions: no tenant is required to list them
     * (the tenant-scoped assignment endpoint enforces onboarding instead).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Role::orderBy('id')->get(['slug', 'name']),
        ]);
    }
}
