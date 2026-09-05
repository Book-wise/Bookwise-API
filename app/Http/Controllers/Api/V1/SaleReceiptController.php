<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ReceiptMail;
use App\Models\Sale;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SaleReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receiptService,
    ) {}

    /**
     * GET /sales/{id}/receipt — stream the PDF receipt.
     */
    public function show(int $id): StreamedResponse
    {
        $sale = Sale::with('client')->findOrFail($id);
        $tenant = auth()->user()->tenant;

        $pdf = $this->receiptService->generate($sale, $tenant);

        return response()->streamDownload(
            fn () => print $pdf,
            "receipt-{$sale->id}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * POST /sales/{id}/receipt/send — email the receipt PDF.
     */
    public function send(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $sale = Sale::with('client')->findOrFail($id);
        $tenant = auth()->user()->tenant;

        $pdf = $this->receiptService->generate($sale, $tenant);

        // Base64-encode for queue serialization (raw binary breaks JSON encoding)
        Mail::to($validated['email'])->queue(new ReceiptMail($sale, base64_encode($pdf), $tenant));

        return response()->json(['message' => 'Comprobante enviado']);
    }
}
