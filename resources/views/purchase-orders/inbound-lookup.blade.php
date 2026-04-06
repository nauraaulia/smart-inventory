<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbound dari Nomor PO</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; color: #111827; }
        .container { max-width: 1000px; margin: 0 auto; }
        .card { background: #ffffff; border: 1px solid #d1d5db; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; font-size: 14px; }
        th { background: #f9fafb; text-align: left; color: #111827; }
        .row { display: flex; gap: 8px; }
        input { flex: 1; padding: 8px; border: 1px solid #d1d5db; border-radius: 8px; color: #111827; background: #ffffff; }
        .btn { padding: 10px 14px; border-radius: 8px; border: none; text-decoration: none; display: inline-block; color: #fff; background: #2563eb; cursor: pointer; }
        .btn-muted { background: #4b5563; }
        .btn-danger { background: #dc2626; }
        .flash { margin-bottom: 12px; padding: 10px; border-radius: 8px; }
        .ok { background: #ecfdf5; color: #065f46; border: 1px solid #86efac; }
        .bad { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
<div class="container">
    <h1>Inbound - Cari Nomor Surat PO</h1>

    @if (session('success'))
        <div class="flash ok">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash bad">{{ session('error') }}</div>
    @endif

    <div class="card">
        <form method="GET" action="{{ route('inbound.receive-po') }}">
            <div class="row">
                <input type="text" name="po_number" value="{{ $poNumber }}" placeholder="Contoh: PO/SI/IV/2026/0001">
                <button class="btn" type="submit">Cari PO</button>
                <a class="btn btn-muted" href="{{ \App\Filament\Resources\Inbounds\InboundResource::getUrl('index') }}">Kembali Inbound</a>
            </div>
        </form>
    </div>

    @if ($poNumber !== '' && !$purchaseOrder)
        <div class="card">Nomor PO tidak ditemukan.</div>
    @endif

    @if ($purchaseOrder)
        <div class="card">
            <div><strong>Nomor PO:</strong> {{ $purchaseOrder->po_number }}</div>
            <div><strong>Supplier:</strong> {{ $purchaseOrder->supplier?->nama_supplier ?? '-' }}</div>
            <div><strong>Status:</strong> {{ $purchaseOrder->status }}</div>

            <table>
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Harusnya Masuk (Komersial)</th>
                        <th>Data Teknis Sistem</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($purchaseOrder->items as $item)
                    <tr>
                        <td>{{ $item->material?->nama_material ?? '-' }}</td>
                        <td>{{ number_format((float) $item->qty_order, 1, '.', '') }} {{ $item->unit_order }}</td>
                        <td>{{ number_format((float) $item->qty_system, 1, '.', '') }} {{ $item->unit_system }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($purchaseOrder->status === 'waiting_inbound')
                <form method="POST" action="{{ route('po.receive', $purchaseOrder->id) }}" style="margin-top: 14px;">
                    @csrf
                    <table>
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Pesanan</th>
                                <th>Sudah Diterima</th>
                                <th>Sisa</th>
                                <th>Qty Datang Sekarang</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($purchaseOrder->items as $item)
                            @php
                                $ordered = (float) $item->qty_order;
                                $received = (float) ($item->qty_received ?? 0);
                                $remaining = max(0, $ordered - $received);
                            @endphp
                            <tr>
                                <td>{{ $item->material?->nama_material ?? '-' }}</td>
                                <td>{{ number_format($ordered, 1, '.', '') }} {{ $item->unit_order }}</td>
                                <td>{{ number_format($received, 1, '.', '') }} {{ $item->unit_order }}</td>
                                <td>{{ number_format($remaining, 1, '.', '') }} {{ $item->unit_order }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" max="{{ $remaining }}" name="received_quantities[{{ $item->id }}]" value="{{ $remaining > 0 ? number_format($remaining, 2, '.', '') : '0' }}">
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    <div class="row">
                        <input type="text" name="receiver_name" value="{{ auth()->user()?->name }}" placeholder="Nama penerima">
                        <button class="btn btn-danger" type="submit">Terima</button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</div>
</body>
</html>
