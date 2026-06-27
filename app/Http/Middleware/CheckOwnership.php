<?php

namespace App\Http\Middleware;

use App\Models\Provider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admins bypass ownership checks
        if ($user?->role?->value === 'admin') {
            return $next($request);
        }

        // Providers must own the resource via their locations
        if ($user?->role?->value === 'provider') {
            $userProvider = Provider::with('locations')
                ->where('user_id', $user->id)
                ->first();

            if (! $userProvider) {
                return response()->json([
                    'error' => 'forbidden',
                    'detail' => 'Provider profile not found.',
                ], 403);
            }

            // Store provider and their location IDs for later use (controller/scopes)
            $request->attributes->set('provider_user', $userProvider);
            $request->attributes->set('provider_location_ids', $userProvider->locations->pluck('id')->toArray());
        }

        return $next($request);
    }
}
