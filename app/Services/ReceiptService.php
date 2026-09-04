<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptService
{
    public function __construct(
        private readonly LogoService $logos,
    ) {}

    /**
     * Generate a PDF receipt for the given sale and tenant.
     *
     * @return string Raw PDF bytes
     */
    public function generate(Sale $sale, ?Tenant $tenant): string
    {
        $sale->load(['client', 'transactions']);

        $pdf = Pdf::loadView('receipts.sale', [
            'sale' => $sale,
            'tenant' => $tenant,
            'logoData' => $this->logos->dataUri($tenant),
        ]);

        return $pdf->output();
    }
}
