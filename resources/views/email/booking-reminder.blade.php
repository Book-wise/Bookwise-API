<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .body { padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .footer { text-align: center; padding: 15px; color: #6b7280; font-size: 12px; }
        .detail { margin: 10px 0; padding: 10px; background: white; border-radius: 6px; border-left: 4px solid #2563eb; }
        .label { font-weight: bold; color: #6b7280; font-size: 12px; text-transform: uppercase; }
        .value { font-size: 16px; color: #111; }
        .urgent { border-left-color: #dc2626; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $type === '30m' ? 'Tu reserva es en 30 minutos' : 'Recordatorio de Reserva' }}</h1>
        </div>
        <div class="body">
            <p>Hola <strong>{{ $clientName }}</strong>,</p>
            <p>{{ $type === '30m' ? 'Tu reserva comienza en 30 minutos. No olvides asistir.' : 'Te recordamos que tienes una reserva agendada para mañana:' }}</p>

            <div class="detail {{ $type === '30m' ? 'urgent' : '' }}">
                <div class="label">Fecha</div>
                <div class="value">{{ $date }}</div>
            </div>
            <div class="detail">
                <div class="label">Hora</div>
                <div class="value">{{ $time }}</div>
            </div>
            @if ($serviceName)
            <div class="detail">
                <div class="label">Servicio</div>
                <div class="value">{{ $serviceName }}</div>
            </div>
            @endif
            <div class="detail">
                <div class="label">Profesional</div>
                <div class="value">{{ $providerName }}</div>
            </div>
            <div class="detail">
                <div class="label">Ubicación</div>
                <div class="value">{{ $locationName }}</div>
            </div>
            @if ($address)
            <div class="detail">
                <div class="label">Dirección</div>
                <div class="value">{{ $address }}</div>
            </div>
            @endif

            <p style="margin-top: 20px;">
                @if ($type === '30m')
                    Dirígete a {{ $locationName }} para tu atención.
                @else
                    Si no puedes asistir, por favor cancela tu reserva con anticipación.
                @endif
            </p>
        </div>
        <div class="footer">
            <p>Bienestar y tecnologia al servicio de tu piel</p>
        </div>
    </div>
</body>
</html>
