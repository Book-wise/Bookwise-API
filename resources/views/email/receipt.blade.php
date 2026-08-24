<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #059669; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .body { padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .footer { text-align: center; padding: 15px; color: #6b7280; font-size: 12px; }
        .detail { margin: 10px 0; padding: 10px; background: white; border-radius: 6px; border-left: 4px solid #059669; }
        .label { font-weight: bold; color: #6b7280; font-size: 12px; text-transform: uppercase; }
        .value { font-size: 16px; color: #111; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Comprobante de Venta</h1>
        </div>
        <div class="body">
            <p>Hola <strong>{{ $clientName }}</strong>,</p>
            <p>Adjunto encontrarás el comprobante de tu venta #{{ $saleId }}.</p>

            <div class="detail">
                <div class="label">Total</div>
                <div class="value">${{ $total }}</div>
            </div>
        </div>
        <div class="footer">
            <p>Bienestar y tecnología al servicio de tu piel</p>
        </div>
    </div>
</body>
</html>
