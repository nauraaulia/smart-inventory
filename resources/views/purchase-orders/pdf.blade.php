<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PO {{ $purchaseOrder->po_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { margin-bottom: 12px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 6px; }
        th { background: #f3f4f6; text-align: left; }
        .title-block { text-align: center; margin-bottom: 10px; }
        .party-table td { vertical-align: top; border: 1px solid #d1d5db; }
        .party-label { width: 16%; font-weight: 700; background: #f9fafb; }
        .signature-table td { border: none; padding-top: 28px; text-align: center; }
        .signature-name { margin-top: 36px; font-weight: 700; }
    </style>
</head>
<body>
    <div class="title-block">
        <h1>SURAT PURCHASE ORDER</h1>
    </div>

    <div class="meta">
        <div><strong>Nomor Surat:</strong> {{ $purchaseOrder->po_number }}</div>
        <div><strong>Tanggal:</strong> {{ optional($purchaseOrder->po_date)->format('d M Y') }}</div>
        <div><strong>Perihal:</strong> Pemesanan Material Proyek</div>
    </div>

    <table class="party-table" style="margin-bottom: 12px;">
        <tbody>
            <tr>
                <td class="party-label">From</td>
                <td>
                    <div><strong>PT Julian Adiputra Utama</strong></div>
                    <div>Jl. Parameswara Lrg. Macan Putih No.3216, Bukit Baru, Kec. Ilir Bar. I, Kota Palembang, Sumatera Selatan 30131</div>
                </td>
            </tr>
            <tr>
                <td class="party-label">To</td>
                <td>
                    <div><strong>{{ $purchaseOrder->supplier?->nama_supplier ?? '-' }}</strong></div>
                    <div><strong>Alamat:</strong> {{ $purchaseOrder->supplier?->alamat ?: '-' }}</div>
                </td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Material</th>
                <th>Qty Pesan</th>
                <th>Satuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($purchaseOrder->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->material?->nama_material ?? '-' }}</td>
                    <td>{{ number_format((float) $item->qty_order, 1, '.', '') }}</td>
                    <td>{{ $item->unit_order }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signature-table" style="margin-top: 20px; width: 100%;">
        <tr>
            <td>
                Menyetujui,<br>Manager Operasional
                <div class="signature-name">(........................)</div>
            </td>
            <td>
                Mengetahui,<br>Finance
                <div class="signature-name">(........................)</div>
            </td>
            <td>
                Diterima Supplier,<br>
                <div class="signature-name">(........................)</div>
            </td>
        </tr>
    </table>
</body>
</html>
