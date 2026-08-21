<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $abilities = $token->abilities ?? [];

        $hasScope = in_array('*', $abilities) || in_array($scope, $abilities);

        if (! $hasScope) {
            return response()->json([
                'error' => 'forbidden',
                'detail' => "Token missing required scope: {$scope}",
            ], 403);
        }

        return $next($request);
    }
}
