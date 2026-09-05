<?php

namespace App\Mail;

use App\Models\Sale;
use App\Models\Tenant;
use App\Services\LogoService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Sale $sale,
        public string $pdf,
        public ?Tenant $tenant = null,
    ) {
        //
    }

    /**
     * Logo del negocio como data URI (incrustado en el correo).
     */
    private function logoData(): ?string
    {
        return app(LogoService::class)->dataUri($this->tenant);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $businessName = $this->tenant?->business_name ?? 'Kinesilk';

        return new Envelope(
            subject: "Comprobante de venta - {$businessName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $clientName = $this->sale->client?->first_name ?? '';

        return new Content(
            html: 'email.receipt',
            with: [
                'clientName' => $clientName,
                'saleId' => $this->sale->id,
                'total' => number_format((float) $this->sale->total, 0, ',', '.'),
                'businessName' => $this->tenant?->business_name ?? 'Kinesilk',
                'businessRut' => $this->tenant?->business_rut,
                'logoData' => $this->logoData(),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => base64_decode($this->pdf), "receipt-{$this->sale->id}.pdf")->withMime('application/pdf'),
        ];
    }
}
