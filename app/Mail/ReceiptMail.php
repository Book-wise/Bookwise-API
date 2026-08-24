<?php

namespace App\Mail;

use App\Models\Sale;
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
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Comprobante de venta - Kinesilk',
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
            Attachment::fromData(fn () => $this->pdf, "receipt-{$this->sale->id}.pdf", mime: 'application/pdf'),
        ];
    }
}
