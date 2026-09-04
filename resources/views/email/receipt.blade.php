<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #059669; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .brand { display: inline-flex; align-items: center; gap: 12px; color: #fff; }
        .brand__avatar { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; }
        .brand__avatar--empty { width: 44px; height: 44px; border-radius: 10px; background: rgba(255,255,255,0.25); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; }
        .brand__name { font-size: 18px; font-weight: bold; margin: 0; line-height: 1.2; }
        .brand__rut { font-size: 12px; opacity: 0.9; }
        .body { padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .detail { margin: 10px 0; padding: 12px; background: white; border-radius: 6px; border-left: 4px solid #059669; }
        .label { font-weight: bold; color: #6b7280; font-size: 12px; text-transform: uppercase; }
        .value { font-size: 16px; color: #111; }
        .footer { text-align: center; padding: 15px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="brand">
                @if($logoData)
                    <img class="brand__avatar" src="{{ $logoData }}" alt="Logo {{ $businessName }}">
                @else
                    <div class="brand__avatar--empty">{{ strtoupper(mb_substr($businessName, 0, 1)) }}</div>
                @endif
                <div style="text-align:left">
                    <p class="brand__name">{{ $businessName }}</p>
                    @if($businessRut)
                        <div class="brand__rut">RUT: {{ $businessRut }}</div>
                    @endif
                </div>
            </div>
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
            <p>{{ $businessName }} · Bienestar y tecnología al servicio de tu piel</p>
        </div>
    </div>
</body>
</html>
