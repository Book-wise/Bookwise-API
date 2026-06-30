<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SaleResource;
use App\Http\Resources\V1\SaleTransactionResource;
use App\Mail\PaymentConfirmation;
use App\Models\Booking;
use App\Models\ClientPack;
use App\Models\Sale;
use App\Models\SaleTransaction;
use App\Services\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SaleController extends Controller
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
    ) {}

    // ── GET /v1/sales ──────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $sales = Sale::with(['client', 'booking.service', 'booking.provider', 'clientPack.servicePack'])
            ->when($request->booking_id, fn ($q) => $q->where('booking_id', $request->booking_id))
            ->when($request->client_pack_id, fn ($q) => $q->where('client_pack_id', $request->client_pack_id))
            ->when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->wc_order_id, fn ($q) => $q->where('wc_order_id', $request->wc_order_id))
            ->when($request->payment_method, fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->payment_status, fn ($q) => match ($request->payment_status) {
                'paid' => $q->whereColumn('paid_amount', '>=', 'total'),
                'partial' => $q->where('paid_amount', '>', 0)->whereColumn('paid_amount', '<', 'total'),
                'unpaid' => $q->where('paid_amount', '<=', 0),
                default => $q,
            })
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json(SaleResource::collection($sales));
    }

    // ── GET /v1/sales/{id} ─────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $sale = Sale::with([
            'client',
            'booking.client', 'booking.service', 'booking.provider', 'booking.location',
            'booking.status', 'booking.sale',
            'booking.packSession.clientPack.servicePack.service',
            'booking.packSession.clientPack.sessions' => fn ($q) => $q->orderBy('session_number'),
            'booking.packSession.clientPack.sessions.clientPack.servicePack.service',
            'booking.packSession.clientPack.sessions.booking.provider',
            'booking.packSession.clientPack.sessions.booking.location',
            'booking.packSession.clientPack.sessions.booking.status',
            'clientPack.servicePack.service',
            'clientPack.sessions' => fn ($q) => $q->orderBy('session_number'),
            'clientPack.sessions.clientPack.servicePack.service',
            'clientPack.sessions.booking.provider',
            'clientPack.sessions.booking.location',
            'clientPack.sessions.booking.status',
            'transactions',
        ])->findOrFail($id);

        return response()->json(['data' => new SaleResource($sale)]);
    }

    // ── POST /v1/sales ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'client_pack_id' => ['nullable', 'integer', 'exists:client_packs,id'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:100'],
        ]);

        $hasBooking = filled($validated['booking_id'] ?? null);
        $hasPack = filled($validated['client_pack_id'] ?? null);

        if ($hasBooking && $hasPack) {
            return response()->json([
                'error' => 'invalid_input',
                'detail' => 'Provide booking_id or client_pack_id, not both.',
            ], 422);
        }

        if (! $hasBooking && ! $hasPack) {
            return response()->json([
                'error' => 'invalid_input',
                'detail' => 'You must provide either booking_id or client_pack_id.',
            ], 422);
        }

        $endpoint = 'POST /v1/sales';
        $requestHash = md5($request->getContent());
        $hasIdempotencyKey = $request->hasHeader('Idempotency-Key');

        if ($hasIdempotencyKey) {
            $cached = $this->idempotency->check($request, $endpoint);
            if ($cached !== null) {
                return $cached;
            }

            $status = $this->idempotency->acquire($request, $endpoint, $requestHash);
            if ($status === 1) {
                return response()->json([
                    'error' => 'conflict',
                    'detail' => 'A request with this idempotency key is already in progress or conflicts.',
                ], 409);
            }
        }

        $existingError = null;

        try {
            $sale = DB::transaction(function () use ($validated, $hasBooking, &$existingError) {
                if ($hasBooking) {
                    // Lock the parent Booking row to serialize concurrent sale creation
                    $booking = Booking::lockForUpdate()->with('service')->findOrFail($validated['booking_id']);

                    if (Sale::where('booking_id', $validated['booking_id'])->exists()) {
                        $existingError = 'booking';

                        return null;
                    }

                    $clientId = $booking->client_id;
                    $defaultTotal = $booking->price ?? $booking->service?->price ?? 0;
                } else {
                    // Lock the parent ClientPack row to serialize concurrent sale creation
                    $clientPack = ClientPack::lockForUpdate()->with('servicePack')->findOrFail($validated['client_pack_id']);

                    if (Sale::where('client_pack_id', $validated['client_pack_id'])->exists()) {
                        $existingError = 'pack';

                        return null;
                    }

                    $clientId = $clientPack->client_id;
                    $defaultTotal = $clientPack->servicePack?->price ?? 0;
                }

                return Sale::create([
                    'booking_id' => $validated['booking_id'] ?? null,
                    'client_pack_id' => $validated['client_pack_id'] ?? null,
                    'client_id' => $clientId,
                    'total' => $validated['total'] ?? $defaultTotal,
                    'paid_amount' => 0,
                    'payment_method' => $validated['payment_method'] ?? null,
                ]);
            });
        } catch (\Throwable $e) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $e;
        }

        if ($existingError !== null) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => 'sale_already_exists',
                'detail' => 'A sale already exists for this '.$existingError.'.',
            ], 422);
        }

        $sale->load(['client', 'booking', 'clientPack.servicePack', 'transactions']);

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 201, ['data' => new SaleResource($sale)]);
        }

        return response()->json(['data' => new SaleResource($sale)], 201);
    }

    // ── PATCH /v1/sales/{id} ───────────────────────────────────────
    public function update(int $id, Request $request): JsonResponse
    {
        $sale = Sale::findOrFail($id);

        $validated = $request->validate([
            'total' => ['sometimes', 'numeric', 'min:0'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $sale->update($validated);
        $sale->load(['client', 'booking', 'clientPack.servicePack', 'transactions']);

        return response()->json(['data' => new SaleResource($sale)]);
    }

    // ── GET /v1/sales/{id}/transactions ───────────────────────────
    public function listTransactions(int $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);

        return response()->json([
            'data' => SaleTransactionResource::collection($sale->transactions),
            'sale' => [
                'total' => $sale->total,
                'paid_amount' => $sale->paid_amount,
                'remaining_amount' => $sale->remaining_amount,
                'payment_status' => $sale->payment_status,
            ],
        ]);
    }

    // ── POST /v1/sales/{id}/transactions ──────────────────────────
    public function registerTransaction(int $id, Request $request): JsonResponse
    {
        $endpoint = 'POST /v1/sales/'.$id.'/transactions';
        $requestHash = md5($request->getContent());
        $hasIdempotencyKey = $request->hasHeader('Idempotency-Key');

        if ($hasIdempotencyKey) {
            $cached = $this->idempotency->check($request, $endpoint);
            if ($cached !== null) {
                return $cached;
            }

            $status = $this->idempotency->acquire($request, $endpoint, $requestHash);
            if ($status === 1) {
                return response()->json([
                    'error' => 'conflict',
                    'detail' => 'A request with this idempotency key is already in progress or conflicts.',
                ], 409);
            }
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
        ]);

        try {
            $result = DB::transaction(function () use ($id, $validated) {
                $sale = Sale::lockForUpdate()->findOrFail($id);

                if ((float) $validated['amount'] > ($sale->remaining_amount + 0.01)) {
                    return [
                        'error' => 'amount_exceeds_remaining',
                        'remaining' => $sale->remaining_amount,
                    ];
                }

                $transaction = $sale->transactions()->create([
                    'amount' => $validated['amount'],
                    'payment_method' => $validated['payment_method'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'paid_at' => $validated['paid_at'] ?? now(),
                ]);

                $sale->recalculatePaidAmount();
                $sale->refresh();

                return [
                    'transaction' => $transaction,
                    'sale' => [
                        'total' => $sale->total,
                        'paid_amount' => $sale->paid_amount,
                        'remaining_amount' => $sale->remaining_amount,
                        'payment_status' => $sale->payment_status,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $e;
        }

        if (isset($result['error'])) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => $result['error'],
                'detail' => 'The amount exceeds the remaining balance of the sale.',
                'remaining' => $result['remaining'],
            ], 422);
        }

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 201, [
                'data' => new SaleTransactionResource($result['transaction']),
                'sale' => $result['sale'],
            ]);
        }

        // Notificación de pago (solo si el cliente tiene habilitadas las notificaciones)
        $sale = Sale::with('client')->find($id);
        if ($sale && $sale->client?->notifications_enabled && $sale->client->email) {
            Mail::to($sale->client->email)->send(new PaymentConfirmation($sale, (float) $validated['amount']));
        }

        return response()->json([
            'data' => new SaleTransactionResource($result['transaction']),
            'sale' => $result['sale'],
        ], 201);
    }

    // ── DELETE /v1/sales/{id}/transactions/{transactionId} ────────
    public function destroyTransaction(int $id, int $transactionId): JsonResponse
    {
        $sale = Sale::findOrFail($id);
        $transaction = SaleTransaction::where('sale_id', $sale->id)->findOrFail($transactionId);

        $transaction->delete();
        $sale->recalculatePaidAmount();
        $sale->refresh();

        return response()->json([
            'message' => 'Transaction voided successfully.',
            'sale' => [
                'total' => $sale->total,
                'paid_amount' => $sale->paid_amount,
                'remaining_amount' => $sale->remaining_amount,
                'payment_status' => $sale->payment_status,
            ],
        ]);
    }
}
