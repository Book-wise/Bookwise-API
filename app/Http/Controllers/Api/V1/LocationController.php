<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationStoreRequest;
use App\Http\Resources\V1\LocationResource;
use App\Models\Comuna;
use App\Models\Location;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locations = Location::query()
            ->when($request->active !== null, fn ($q) => $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name')
            ->paginate($request->per_page ?? 15);

        return response()->json(LocationResource::collection($locations));
    }

    public function store(LocationStoreRequest $request, LocationService $locationService): JsonResponse
    {
        $timezone = $locationService->resolveTimezone((int) $request->input('region_id'));

        $data = [
            'name' => $request->input('name'),
            'address' => $request->input('address'),
            'city' => $request->input('city'),
            'region_id' => $request->input('region_id'),
            'comuna_id' => $request->input('comuna_id'),
            'timezone' => $timezone,
            'active' => $request->boolean('active', true),
        ];

        if ($request->has('opening_time')) {
            $data['opening_time'] = $request->input('opening_time');
        }

        if ($request->has('closing_time')) {
            $data['closing_time'] = $request->input('closing_time');
        }

        $location = Location::create($data);

        $location->load(['region', 'comuna']);

        return response()->json([
            'data' => new LocationResource($location),
            'message' => 'Sucursal creada exitosamente',
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $location = Location::with('providers', 'region', 'comuna')->findOrFail($id);

        return response()->json(['data' => new LocationResource($location)]);
    }

    public function update(Request $request, int $id, LocationService $locationService): JsonResponse
    {
        $location = Location::findOrFail($id);

        $rules = [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('locations')->ignore($id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region_id' => ['sometimes', 'integer', 'exists:regions,id'],
            'comuna_id' => ['sometimes', 'nullable', 'integer', 'exists:comunas,id'],
            'opening_time' => ['sometimes', 'nullable', 'string', 'date_format:H:i:s'],
            'closing_time' => ['sometimes', 'nullable', 'string', 'date_format:H:i:s'],
            'active' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($v) use ($request, $location) {
            if ($v->errors()->has('region_id') || $v->errors()->has('comuna_id')) {
                return;
            }

            $comunaId = $request->input('comuna_id');
            if ($comunaId === null || $comunaId === '') {
                return;
            }

            $regionId = $request->input('region_id', $location->region_id);
            $comuna = Comuna::find($comunaId);
            if (! $comuna || (int) $comuna->region_id !== (int) $regionId) {
                $v->errors()->add('comuna_id', 'La comuna no pertenece a la región seleccionada.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Detect if the request is trying to deactivate the location
        $isTryingToDeactivate = $request->has('active')
            && $location->active
            && ! filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);

        $isForced = $request->boolean('force', false);

        // Run preflight check when deactivating without force
        if ($isTryingToDeactivate && ! $isForced) {
            $preflight = $locationService->checkDeactivationPreflight($location->id);

            if ($preflight['has_conflicts']) {
                return response()->json([
                    'error' => 'deactivation_conflict',
                    'message' => 'La sucursal tiene reservas futuras que impiden su desactivación.',
                    'requires_confirmation' => true,
                    'affects' => [
                        'bookings' => $preflight['bookings'],
                    ],
                ], 409);
            }
        }

        // Log forced deactivations for audit trail
        if ($isTryingToDeactivate && $isForced) {
            $preflight = $locationService->checkDeactivationPreflight($location->id);

            Log::warning('Sucursal desactivada por fuerza mayor', [
                'location_id' => $location->id,
                'location_name' => $location->name,
                'user_id' => $request->user()?->id,
                'user_email' => $request->user()?->email,
                'affected_bookings_count' => count($preflight['bookings']),
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        // Auto-resolve timezone if region changed
        if ($request->has('region_id')) {
            $location->timezone = $locationService->resolveTimezone((int) $request->input('region_id'));
        }

        $updateData = $request->only([
            'name', 'address', 'city', 'region_id', 'comuna_id', 'active',
        ]);

        if ($request->has('opening_time')) {
            $updateData['opening_time'] = $request->input('opening_time');
        }

        if ($request->has('closing_time')) {
            $updateData['closing_time'] = $request->input('closing_time');
        }

        $location->update($updateData);

        $location->refresh();
        $location->load(['region', 'comuna']);

        $message = $isTryingToDeactivate && $isForced
            ? 'Sucursal desactivada. Las reservas existentes no se verán afectadas.'
            : 'Sucursal actualizada exitosamente';

        return response()->json([
            'data' => new LocationResource($location),
            'message' => $message,
        ], 200);
    }
}
