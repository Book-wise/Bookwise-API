<?php

namespace App\Mail;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingReminder extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Booking $booking,
        public string $type = '24h', // '24h' | '30m'
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->type === '30m'
            ? 'Tu reserva en Kinesilk es en 30 minutos'
            : 'Recordatorio: tienes una reserva mañana en Kinesilk';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $start = Carbon::parse($this->booking->start_time);

        return new Content(
            view: 'email.booking-reminder',
            with: [
                'type' => $this->type,
                'clientName' => $this->booking->client->first_name,
                'date' => $start->format('d/m/Y'),
                'time' => $start->format('H:i'),
                'providerName' => $this->booking->provider->first_name.' '.$this->booking->provider->last_name,
                'locationName' => $this->booking->location->name,
                'address' => $this->booking->location->address,
                'serviceName' => $this->booking->service?->name,
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
