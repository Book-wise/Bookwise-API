<?php

namespace App\Http\Middleware;

use App\Models\Provider;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOwnership
{
    public function handle(Request $request, Closure $next, ?string $modelClass = null, string $idParam = 'id'): Response
    {
        $user = $request->user();

        // Admins bypass ownership checks
        if ($user?->role?->value === 'admin') {
            return $next($request);
        }

        // Providers must own the resource via their locations
        if ($user?->role?->value === 'provider') {
            $userProvider = $user->provider;

            if (! $userProvider) {
                return response()->json([
                    'error' => 'forbidden',
                    'detail' => 'Provider profile not found.',
                ], 403);
            }

            // Store provider and their location IDs for later use (controller/scopes)
            $request->attributes->set('provider_user', $userProvider);
            $request->attributes->set('provider_location_ids', [$userProvider->location_id]);
        }

        // When a model class is specified, verify the resource belongs to the provider's locations
        if ($modelClass !== null) {
            $forbidden = $this->resourceOwnership($request, $modelClass, $idParam);

            if ($forbidden !== null) {
                return $forbidden;
            }
        }

        return $next($request);
    }

    /**
     * Verify that the route resource belongs to the provider's allowed locations.
     *
     * @param  string  $modelClass  Fully-qualified model class name
     * @param  string  $routeParam  Route parameter name (default: 'id')
     * @return Response|null A 403 JSON response if not owned, null otherwise
     */
    private function resourceOwnership(Request $request, string $modelClass, string $routeParam): ?Response
    {
        $locationIds = $request->attributes->get('provider_location_ids', []);

        if (empty($locationIds)) {
            return null;
        }

        $routeValue = $request->route()->parameter($routeParam);

        if ($routeValue === null) {
            return null;
        }

        /** @var Model|null $model */
        $model = $routeParam === 'repeatGroupId'
            ? $modelClass::where('repeat_group_id', $routeValue)->first()
            : $modelClass::findOrFail($routeValue);

        if (! $model || ! in_array((int) $model->location_id, $locationIds, true)) {
            return response()->json([
                'error' => 'forbidden',
                'detail' => 'This resource does not belong to your location.',
            ], 403);
        }

        return null;
    }
}
