<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'forbidden', 'detail' => 'Authentication required.'], 403);
        }

        $allowed = array_map(fn(string $r) => UserRole::from($r), $roles);

        if (! in_array($user->role, $allowed)) {
            return response()->json([
                'error'  => 'forbidden',
                'detail' => 'Access denied for your role.',
            ], 403);
        }

        return $next($request);
    }
}
