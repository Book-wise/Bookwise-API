<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClientResource;
use App\Models\Client;
use App\Rules\ChileanRutRule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
     // ── GET /v1/me — Cliente autenticado por email ────────────────
    public function me(Request $request): JsonResponse
    {
        $user   = $request->user();
        $client = Client::where('email', $user->email)->first();

        if (!$client) {
            return response()->json([
                'error'  => 'client_not_found',
                'detail' => 'No Kinesilk client found for this account.',
            ], 404);
        }

        return response()->json(['data' => new ClientResource($client)]);
    }
    // ── GET /v1/clients ────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $clients = Client::query()
            ->when($request->email,  fn($q) => $q->where('email', $request->email))
            ->when($request->search, fn($q) => $q
                ->where('first_name', 'like', "%{$request->search}%")
                ->orWhere('last_name',  'like', "%{$request->search}%")
                ->orWhere('email',      'like', "%{$request->search}%")
                ->orWhere('phone',      'like', "%{$request->search}%")
            )
            ->when($request->active !== null, fn($q) => $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->wc_customer_id,  fn($q) => $q->where('wc_customer_id', $request->wc_customer_id))
            ->orderBy('first_name')
            ->paginate($request->per_page ?? 15);

        return response()->json(ClientResource::collection($clients));
    }

    // ── GET /v1/clients/{id} ───────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $client = Client::with(['bookings.status', 'customAttributes.template'])
            ->findOrFail($id);

        return response()->json(['data' => new ClientResource($client)]);
    }

    // ── POST /v1/clients ───────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'     => ['required', 'string', 'max:100'],
            'last_name'      => ['nullable', 'string', 'max:100'],
            'email'          => ['nullable', 'email', 'unique:clients,email'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'rut'            => ['nullable', 'string', 'max:12', new ChileanRutRule(), 'unique:clients,rut'],
            'gender'         => ['nullable', 'in:male,female,other'],
            'wc_customer_id' => ['nullable', 'integer'],
            'notes'          => ['nullable', 'string'],
        ]);

        $client = Client::create($validated);

        return response()->json(['data' => new ClientResource($client)], 201);
    }

    // ── PATCH /v1/clients/{id} ─────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'first_name'     => ['sometimes', 'string', 'max:100'],
            'last_name'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'          => ['sometimes', 'email', 'unique:clients,email,' . $id],
            'phone'          => ['sometimes', 'nullable', 'string', 'max:20'],
            'rut'            => ['sometimes', 'nullable', 'string', 'max:12', new ChileanRutRule(), 'unique:clients,rut,' . $id],
            'gender'         => ['sometimes', 'nullable', 'in:male,female,other'],
            'wc_customer_id' => ['sometimes', 'nullable', 'integer'],
            'notes'          => ['sometimes', 'nullable', 'string'],
        ]);

        $client->update($validated);

        return response()->json(['data' => new ClientResource($client->fresh())]);
    }

    // ── PATCH /v1/clients/{id}/deactivate ─────────────────────────
    public function deactivate(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        if (!$client->active) {
            return response()->json([
                'error'  => 'already_inactive',
                'detail' => 'This client is already inactive.',
            ], 422);
        }

        $client->update(['active' => false]);

        return response()->json(['data' => new ClientResource($client->fresh())]);
    }
}
