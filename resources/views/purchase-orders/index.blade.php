<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Purchase Order</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; color: #111827; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #ffffff; border: 1px solid #d1d5db; border-radius: 12px; padding: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 10px; font-size: 14px; }
        th { background: #f9fafb; text-align: left; color: #111827; }
        .btn { padding: 7px 10px; border-radius: 8px; border: none; text-decoration: none; display: inline-block; color: #fff; background: #2563eb; }
        .btn-muted { background: #4b5563; }
        .flash { margin-bottom: 12px; padding: 10px; border-radius: 8px; }
        .ok { background: #ecfdf5; color: #065f46; border: 1px solid #86efac; }
        .bad { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
<div class="container">
    <h1>Purchase Orders</h1>

    @if (session('success'))
        <div class="flash ok">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="flash bad">{{ session('error') }}</div>
    @endif

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>Nomor PO</th>
                <th>Tanggal</th>
                <th>Supplier</th>
                <th>Status</th>
                <th>Jumlah Item</th>
                <th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($purchaseOrders as $po)
                <tr>
                    <td>{{ $po->po_number }}</td>
                    <td>{{ optional($po->po_date)->format('Y-m-d') }}</td>
                    <td>{{ $po->supplier?->nama_supplier ?? '-' }}</td>
                    <td>{{ $po->status }}</td>
                    <td>{{ $po->items->count() }}</td>
                    <td>
                        <a class="btn" href="{{ route('po.show', $po->id) }}">Detail</a>
                        <a class="btn btn-muted" target="_blank" href="{{ route('po.print', $po->id) }}">Cetak PDF</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">Belum ada PO.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div style="margin-top: 12px;">
            {{ $purchaseOrders->links() }}
        </div>
    </div>
</div>
</body>
</html>
