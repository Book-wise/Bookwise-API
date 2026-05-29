<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SaleResource;
use App\Http\Resources\V1\SaleTransactionResource;
use App\Models\Booking;
use App\Models\ClientPack;
use App\Models\Sale;
use App\Models\SaleTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    // ── GET /v1/sales ──────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $sales = Sale::with(['client', 'booking.service', 'booking.provider', 'clientPack.servicePack'])
            ->when($request->booking_id,     fn($q) => $q->where('booking_id',     $request->booking_id))
            ->when($request->client_pack_id, fn($q) => $q->where('client_pack_id', $request->client_pack_id))
            ->when($request->client_id,      fn($q) => $q->where('client_id',      $request->client_id))
            ->when($request->wc_order_id,    fn($q) => $q->where('wc_order_id',    $request->wc_order_id))
            ->when($request->payment_method, fn($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->payment_status, fn($q) => match ($request->payment_status) {
                'paid'    => $q->whereColumn('paid_amount', '>=', 'total'),
                'partial' => $q->where('paid_amount', '>', 0)->whereColumn('paid_amount', '<', 'total'),
                'unpaid'  => $q->where('paid_amount', '<=', 0),
                default   => $q,
            })
            ->when($request->date_from, fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to,   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
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
            'booking.packSession.clientPack.sessions'              => fn($q) => $q->orderBy('session_number'),
            'booking.packSession.clientPack.sessions.clientPack.servicePack.service',
            'booking.packSession.clientPack.sessions.booking.provider',
            'booking.packSession.clientPack.sessions.booking.location',
            'booking.packSession.clientPack.sessions.booking.status',
            'clientPack.servicePack.service',
            'clientPack.sessions'                                  => fn($q) => $q->orderBy('session_number'),
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
            'booking_id'      => ['nullable', 'integer', 'exists:bookings,id'],
            'client_pack_id'  => ['nullable', 'integer', 'exists:client_packs,id'],
            'total'           => ['nullable', 'numeric', 'min:0'],
            'payment_method'  => ['nullable', 'string', 'max:100'],
        ]);

        $hasBooking = filled($validated['booking_id'] ?? null);
        $hasPack    = filled($validated['client_pack_id'] ?? null);

        if ($hasBooking && $hasPack) {
            return response()->json([
                'error'  => 'invalid_input',
                'detail' => 'Enviá booking_id o client_pack_id, no los dos a la vez.',
            ], 422);
        }

        if (! $hasBooking && ! $hasPack) {
            return response()->json([
                'error'  => 'invalid_input',
                'detail' => 'Debés enviar booking_id o client_pack_id.',
            ], 422);
        }

        if ($hasBooking) {
            if (Sale::where('booking_id', $validated['booking_id'])->exists()) {
                return response()->json([
                    'error'  => 'sale_already_exists',
                    'detail' => 'Ya existe una venta para esta reserva.',
                ], 422);
            }

            $booking    = Booking::with('service')->findOrFail($validated['booking_id']);
            $clientId   = $booking->client_id;
            $defaultTotal = $booking->price ?? $booking->service?->price ?? 0;
        } else {
            if (Sale::where('client_pack_id', $validated['client_pack_id'])->exists()) {
                return response()->json([
                    'error'  => 'sale_already_exists',
                    'detail' => 'Ya existe una venta para este pack.',
                ], 422);
            }

            $clientPack   = ClientPack::with('servicePack')->findOrFail($validated['client_pack_id']);
            $clientId     = $clientPack->client_id;
            $defaultTotal = $clientPack->servicePack?->price ?? 0;
        }

        $sale = Sale::create([
            'booking_id'     => $validated['booking_id'] ?? null,
            'client_pack_id' => $validated['client_pack_id'] ?? null,
            'client_id'      => $clientId,
            'total'          => $validated['total'] ?? $defaultTotal,
            'paid_amount'    => 0,
            'payment_method' => $validated['payment_method'] ?? null,
        ]);

        $sale->load(['client', 'booking', 'clientPack.servicePack', 'transactions']);

        return response()->json(['data' => new SaleResource($sale)], 201);
    }

    // ── PATCH /v1/sales/{id} ───────────────────────────────────────
    public function update(int $id, Request $request): JsonResponse
    {
        $sale = Sale::findOrFail($id);

        $validated = $request->validate([
            'total'          => ['sometimes', 'numeric', 'min:0'],
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
                'total'            => $sale->total,
                'paid_amount'      => $sale->paid_amount,
                'remaining_amount' => $sale->remaining_amount,
                'payment_status'   => $sale->payment_status,
            ],
        ]);
    }

    // ── POST /v1/sales/{id}/transactions ──────────────────────────
    public function registerTransaction(int $id, Request $request): JsonResponse
    {
        $sale = Sale::findOrFail($id);

        $validated = $request->validate([
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'notes'          => ['nullable', 'string', 'max:500'],
            'paid_at'        => ['nullable', 'date'],
        ]);

        if ((float) $validated['amount'] > ($sale->remaining_amount + 0.01)) {
            return response()->json([
                'error'     => 'amount_exceeds_remaining',
                'detail'    => 'El monto supera el saldo pendiente de la venta.',
                'remaining' => $sale->remaining_amount,
            ], 422);
        }

        $transaction = $sale->transactions()->create([
            'amount'         => $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? null,
            'notes'          => $validated['notes'] ?? null,
            'paid_at'        => $validated['paid_at'] ?? now(),
        ]);

        $sale->recalculatePaidAmount();
        $sale->refresh();

        return response()->json([
            'data' => new SaleTransactionResource($transaction),
            'sale' => [
                'total'            => $sale->total,
                'paid_amount'      => $sale->paid_amount,
                'remaining_amount' => $sale->remaining_amount,
                'payment_status'   => $sale->payment_status,
            ],
        ], 201);
    }

    // ── DELETE /v1/sales/{id}/transactions/{transactionId} ────────
    public function destroyTransaction(int $id, int $transactionId): JsonResponse
    {
        $sale        = Sale::findOrFail($id);
        $transaction = SaleTransaction::where('sale_id', $sale->id)->findOrFail($transactionId);

        $transaction->delete();
        $sale->recalculatePaidAmount();
        $sale->refresh();

        return response()->json([
            'message' => 'Transacción anulada correctamente.',
            'sale'    => [
                'total'            => $sale->total,
                'paid_amount'      => $sale->paid_amount,
                'remaining_amount' => $sale->remaining_amount,
                'payment_status'   => $sale->payment_status,
            ],
        ]);
    }
}
