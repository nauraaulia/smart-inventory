<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Draft PO</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; color: #111827; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #ffffff; border: 1px solid #d1d5db; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        h1 { margin: 0 0 16px; }
        h2 { margin: 0 0 8px; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; font-size: 14px; vertical-align: top; }
        th { background: #f9fafb; text-align: left; color: #111827; }
        input, textarea { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 8px; color: #111827; background: #ffffff; }
        .actions { display: flex; gap: 8px; margin-top: 16px; }
        .btn { padding: 10px 14px; border-radius: 8px; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-secondary { background: #4b5563; color: #fff; }
        .meta { color: #4b5563; font-size: 13px; }
        .error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Review &amp; Adjustment Draft PO</h1>

    @if ($errors->any())
        <div class="error">
            <strong>Validasi gagal:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('po.publish') }}">
        @csrf

        @foreach ($groups as $groupIndex => $group)
            <div class="card">
                <div class="row" style="justify-content: space-between;">
                    <div>
                        <h2>Supplier: {{ $group['supplier_name'] }}</h2>
                        <div class="meta">Tahap konversi komersial untuk supplier ini.</div>
                    </div>
                    <div style="min-width: 220px;">
                        <label>Tanggal PO</label>
                        <input type="date" name="groups[{{ $groupIndex }}][po_date]" value="{{ $group['po_date'] }}" required>
                    </div>
                </div>

                <input type="hidden" name="groups[{{ $groupIndex }}][supplier_id]" value="{{ $group['supplier_id'] }}">

                <table>
                    <thead>
                    <tr>
                        <th>Material</th>
                        <th>Total Kebutuhan Sistem</th>
                        <th>Proyek Asal</th>
                        <th>Qty Pesan (Manual)</th>
                        <th>Satuan Pesan (Manual)</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($group['items'] as $itemIndex => $item)
                        <tr>
                            <td>
                                <strong>{{ $item['material_name'] }}</strong>
                                <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][material_id]" value="{{ $item['material_id'] }}">
                                <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][qty_system]" value="{{ $item['qty_system'] }}">
                                <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][unit_system]" value="{{ $item['unit_system'] }}">
                                @foreach ($item['pr_ids'] as $prId)
                                    <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][pr_ids][]" value="{{ $prId }}">
                                @endforeach
                                @foreach ($item['project_ids'] as $projectId)
                                    <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][project_ids][]" value="{{ $projectId }}">
                                @endforeach
                                @foreach ($item['project_codes'] as $projectCode)
                                    <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][project_codes][]" value="{{ $projectCode }}">
                                @endforeach
                            </td>
                            <td>{{ number_format((float) $item['qty_system'], 1, '.', '') }} {{ $item['unit_system'] }}</td>
                            <td>{{ implode(', ', $item['project_codes']) }}</td>
                            <td>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][qty_order]"
                                    value="{{ $item['qty_order_default'] }}"
                                    required
                                >
                            </td>
                            <td>
                                <input
                                    type="text"
                                    name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][unit_order]"
                                    value="{{ $item['unit_order_default'] }}"
                                    required
                                >
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        <div class="card">
            <label>Catatan PO (opsional)</label>
            <textarea name="notes" rows="3" placeholder="Contoh: Mohon kirim maksimal 7 hari kerja."></textarea>

            <div class="actions">
                <button class="btn btn-primary" type="submit">Simpan &amp; Terbitkan PO</button>
                <a class="btn btn-secondary" href="{{ \App\Filament\Resources\PurchaseRequests\PurchaseRequestResource::getUrl('index') }}">Kembali ke PR</a>
            </div>
        </div>
    </form>
</div>
</body>
</html>
