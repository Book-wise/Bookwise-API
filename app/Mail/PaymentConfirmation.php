<?php

namespace App\Mail;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Sale $sale,
        public float $amount,
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirmación de pago - Kinesilk',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $clientName = $this->sale->client?->first_name ?? $this->sale->booking?->client?->first_name ?? '';

        return new Content(
            view: 'email.payment-confirmation',
            with: [
                'clientName' => $clientName,
                'amount' => number_format($this->amount, 0, ',', '.'),
                'paidAmount' => number_format($this->sale->paid_amount, 0, ',', '.'),
                'total' => number_format($this->sale->total, 0, ',', '.'),
                'remainingAmount' => number_format($this->sale->remaining_amount, 0, ',', '.'),
                'paymentStatus' => match ($this->sale->payment_status) {
                    'Pagado' => 'totalmente pagado',
                    'Parcial' => 'pago parcial',
                    default => 'pendiente',
                },
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
        return [];
    }
}
