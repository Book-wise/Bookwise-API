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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Reserva Confirmada</h1>
        </div>
        <div class="body">
            <p>Hola <strong>{{ $clientName }}</strong>,</p>
            <p>Tu reserva ha sido confirmada. Aquí están los detalles:</p>

            <div class="detail">
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
            @if ($price)
            <div class="detail">
                <div class="label">Valor</div>
                <div class="value">${{ $price }}</div>
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

            <p style="margin-top: 20px;">Si tienes alguna duda, puedes contactarnos respondiendo este correo.</p>
        </div>
        <div class="footer">
            <p>Bienestar y tecnologia al servicio de tu piel</p>
        </div>
    </div>
</body>
</html>
