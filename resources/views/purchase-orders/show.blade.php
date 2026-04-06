<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail PO {{ $purchaseOrder->po_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; color: #111827; }
        .container { max-width: 1100px; margin: 0 auto; }
        .card { background: #ffffff; border: 1px solid #d1d5db; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; font-size: 14px; }
        th { background: #f9fafb; text-align: left; color: #111827; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn { padding: 10px 14px; border-radius: 8px; border: none; text-decoration: none; display: inline-block; color: #fff; background: #2563eb; }
        .btn-muted { background: #4b5563; }
        .btn-danger { background: #dc2626; }
        .flash { margin-bottom: 12px; padding: 10px; border-radius: 8px; }
        .ok { background: #ecfdf5; color: #065f46; border: 1px solid #86efac; }
        .bad { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
<div class="container">
    <h1>Detail PO {{ $purchaseOrder->po_number }}</h1>

    @if (session('success'))
        <div class="flash ok">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash bad">{{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="row">
            <div><strong>Supplier:</strong> {{ $purchaseOrder->supplier?->nama_supplier ?? '-' }}</div>
            <div><strong>Tanggal:</strong> {{ optional($purchaseOrder->po_date)->format('Y-m-d') }}</div>
            <div><strong>Status:</strong> {{ $purchaseOrder->status }}</div>
        </div>

        <table>
            <thead>
            <tr>
                <th>Material</th>
                <th>Kebutuhan Sistem</th>
                <th>Pesanan Komersial</th>
                <th>Project Ref</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($purchaseOrder->items as $item)
                <tr>
                    <td>{{ $item->material?->nama_material ?? '-' }}</td>
                    <td>{{ number_format((float) $item->qty_system, 1, '.', '') }} {{ $item->unit_system }}</td>
                    <td>{{ number_format((float) $item->qty_order, 1, '.', '') }} {{ $item->unit_order }}</td>
                    <td>{{ implode(', ', $item->project_codes ?? []) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <a class="btn" target="_blank" href="{{ route('po.print', $purchaseOrder->id) }}">Cetak PDF</a>
        <a class="btn btn-muted" href="{{ route('po.index') }}">Kembali ke Daftar PO</a>

        @if ($purchaseOrder->status === 'waiting_inbound')
            <form method="POST" action="{{ route('po.receive', $purchaseOrder->id) }}" style="margin-top: 14px;">
                @csrf
                <div class="row">
                    <div style="min-width: 260px;">
                        <label>Nama Penerima</label>
                        <input type="text" name="receiver_name" value="{{ auth()->user()?->name }}" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:8px;">
                    </div>
                    <div style="align-self: end;">
                        <button class="btn btn-danger" type="submit">Terima PO Menjadi Inbound</button>
                    </div>
                </div>

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
                                <input type="number" step="0.01" min="0" max="{{ $remaining }}" name="received_quantities[{{ $item->id }}]" value="{{ $remaining > 0 ? number_format($remaining, 2, '.', '') : '0' }}" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:8px;">
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </form>
        @endif
    </div>
</div>
</body>
</html>
