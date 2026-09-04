<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; margin: 0; padding: 20px; }

        /* ── Marca / avatar del negocio ─────────────────────────────────── */
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 16px;
            margin-bottom: 18px;
            border-bottom: 2px solid #059669;
        }
        .brand__avatar {
            width: 64px;
            height: 64px;
            border-radius: 12px;
            object-fit: cover;
            flex-shrink: 0;
        }
        .brand__avatar--empty {
            width: 64px;
            height: 64px;
            border-radius: 12px;
            background: #059669;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: bold;
            flex-shrink: 0;
        }
        .brand__info { min-width: 0; }
        .brand__name { font-size: 20px; font-weight: bold; color: #059669; margin: 0; line-height: 1.2; }
        .brand__meta { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .brand__doc {
            margin-left: auto;
            text-align: right;
            font-size: 12px;
            color: #4b5563;
        }
        .brand__doc strong { color: #111; }

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
    @php
        // El logo ya viene resuelto como data URI desde ReceiptService/LogoService.
        $monogram = $tenant?->business_name ? strtoupper(mb_substr($tenant->business_name, 0, 1)) : 'B';
    @endphp

    {{-- Marca / avatar del negocio --}}
    <div class="brand">
        @if($logoData)
            <img class="brand__avatar" src="{{ $logoData }}" alt="Logo {{ $tenant?->business_name }}">
        @else
            <div class="brand__avatar--empty">{{ $monogram }}</div>
        @endif

        <div class="brand__info">
            @if($tenant?->business_name)
                <p class="brand__name">{{ $tenant->business_name }}</p>
            @endif
            @if($tenant?->business_email)
                <div class="brand__meta">{{ $tenant->business_email }}</div>
            @endif
            @if($tenant?->business_phone)
                <div class="brand__meta">{{ $tenant->business_phone }}</div>
            @endif
        </div>

        <div class="brand__doc">
            @if($tenant?->business_rut)
                <div>RUT: <strong>{{ $tenant->business_rut }}</strong></div>
            @endif
            <div>Comprobante de venta</div>
        </div>
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
            Estado: {{ ucfirst($sale->payment_status_label) }}
        </div>
    </div>

    <div class="footer">
        <p>Comprobante de venta</p>
        @if($tenant?->business_address)
            <p>{{ $tenant->business_address }}</p>
        @endif
    </div>
</body>
</html>
