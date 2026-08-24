<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #059669; padding-bottom: 15px; }
        .header img { max-width: 120px; max-height: 80px; margin-bottom: 8px; }
        .header h1 { font-size: 20px; color: #059669; margin: 0; }
        .header .rut { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 14px; font-weight: bold; color: #059669; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #f3f4f6; text-align: left; padding: 6px 8px; border-bottom: 1px solid #d1d5db; }
        td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        .detail-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
        .detail-label { color: #6b7280; }
        .detail-value { font-weight: bold; }
        .total-row { font-size: 18px; font-weight: bold; color: #059669; border-top: 2px solid #059669; padding-top: 8px; margin-top: 10px; }
        .footer { text-align: center; font-size: 11px; color: #9ca3af; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    </style>
</head>
<body>
    {{-- Tenant Header (nullable-safe) --}}
    <div class="header">
        @if($tenant?->business_logo_url)
            @php
                $logoPath = $tenant->business_logo_url;
                $isAbsolute = str_starts_with($logoPath, '/') || str_starts_with($logoPath, 'http');
                $fullPath = $isAbsolute ? $logoPath : public_path('storage/' . ltrim($logoPath, '/'));
            @endphp
            @if(file_exists($fullPath))
                <img src="file://{{ $fullPath }}" alt="Logo">
            @endif
        @endif
        @if($tenant?->business_name)
            <h1>{{ $tenant->business_name }}</h1>
        @endif
        @if($tenant?->business_rut)
            <div class="rut">RUT: {{ $tenant->business_rut }}</div>
        @endif
    </div>

    {{-- Sale Info --}}
    <div class="section">
        <div class="section-title">Datos de la Venta</div>
        <div class="detail-row">
            <span class="detail-label">Nº Venta</span>
            <span class="detail-value">#{{ $sale->id }}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Fecha</span>
            <span class="detail-value">{{ $sale->created_at->format('d/m/Y H:i') }}</span>
        </div>
        @if($sale->client)
        <div class="detail-row">
            <span class="detail-label">Cliente</span>
            <span class="detail-value">{{ $sale->client->first_name }} {{ $sale->client->last_name }}</span>
        </div>
        @endif
        @if($sale->payment_method)
        <div class="detail-row">
            <span class="detail-label">Método de pago</span>
            <span class="detail-value">{{ ucfirst($sale->payment_method->value) }}</span>
        </div>
        @endif
    </div>

    {{-- Transactions --}}
    @if($sale->transactions->count())
    <div class="section">
        <div class="section-title">Transacciones</div>
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Monto</th>
                    <th>Método</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->transactions as $tx)
                <tr>
                    <td>{{ $tx->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td>${{ number_format((float) $tx->amount, 0, ',', '.') }}</td>
                    <td>{{ $tx->payment_method ? ucfirst($tx->payment_method->value) : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Totals --}}
    <div class="section">
        <div class="detail-row">
            <span class="detail-label">Total</span>
            <span class="detail-value">${{ number_format((float) $sale->total, 0, ',', '.') }}</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Pagado</span>
            <span class="detail-value">${{ number_format((float) $sale->paid_amount, 0, ',', '.') }}</span>
        </div>
        @if($sale->remaining_amount > 0)
        <div class="detail-row">
            <span class="detail-label">Saldo pendiente</span>
            <span class="detail-value">${{ number_format($sale->remaining_amount, 0, ',', '.') }}</span>
        </div>
        @endif
        <div class="total-row">
            Estado: {{ ucfirst($sale->payment_status) }}
        </div>
    </div>

    <div class="footer">
        <p>Comprobante de venta</p>
    </div>
</body>
</html>
