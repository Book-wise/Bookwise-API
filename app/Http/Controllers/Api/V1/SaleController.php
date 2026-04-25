<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sales = Sale::with(['booking.client', 'booking.service', 'booking.provider'])
            ->when($request->booking_id,    fn($q) => $q->where('booking_id',    $request->booking_id))
            ->when($request->wc_order_id,   fn($q) => $q->where('wc_order_id',   $request->wc_order_id))
            ->when($request->payment_method,fn($q) => $q->where('payment_method',$request->payment_method))
            ->when($request->date_from,     fn($q) => $q->whereDate('paid_at', '>=', $request->date_from))
            ->when($request->date_to,       fn($q) => $q->whereDate('paid_at', '<=', $request->date_to))
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($sales);
    }

    public function show(int $id): JsonResponse
    {
        $sale = Sale::with(['booking.client', 'booking.service', 'booking.provider', 'booking.location'])
            ->findOrFail($id);

        return response()->json(['data' => $sale]);
    }
}
