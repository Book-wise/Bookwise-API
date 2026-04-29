<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'forbidden', 'detail' => 'Authentication required.'], 403);
        }

        $requiredRole = UserRole::from($role);

        if ($user->role !== $requiredRole) {
            return response()->json([
                'error'  => 'forbidden',
                'detail' => "This endpoint requires role: {$role}",
            ], 403);
        }

        return $next($request);
    }
}