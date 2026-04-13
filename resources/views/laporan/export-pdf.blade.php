<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Operasional</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { margin: 0 0 8px; font-size: 18px; }
        h2 { margin: 16px 0 6px; font-size: 14px; }
        h3 { margin: 10px 0 4px; font-size: 12px; }
        p { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 5px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    @php
        $financeData = $roleData['finance'] ?? [];
        $opsData = $roleData['manager_operasional'] ?? [];
        $teknikData = $roleData['manager_teknik'] ?? [];
        $gudangData = $roleData['staff_administrasi_gudang'] ?? [];

        $financeActivity = (string) ($filters['finance_activity'] ?? 'all');
        $opsActivity = (string) ($filters['ops_activity'] ?? 'all');
        $teknikActivity = (string) ($filters['teknik_activity'] ?? 'all');
        $gudangActivity = (string) ($filters['gudang_activity'] ?? 'all');
    @endphp

    <h1>Laporan Operasional</h1>
    <p><strong>Sistem:</strong> Smart Inventory</p>
    <p><strong>Role Login:</strong> {{ $roleName }}</p>
    <p><strong>Periode:</strong> {{ $dateFrom ?: '-' }} s/d {{ $dateTo ?: '-' }}</p>

    @if ($showFinance)
        <h2>Laporan Finance</h2>
        @if ($financeActivity === 'all' || $financeActivity === 'pr')
            <h3>PR Disetujui Finance</h3>
            <table>
                <thead>
                    <tr>
                        <th>No PR</th>
                        <th>Material</th>
                        <th>Qty</th>
                        <th>Diajukan oleh</th>
                        <th>Tgl ACC Finance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($financeData['pr_disetujui_finance'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['no_pr'] }}</td>
                            <td>{{ $row['material'] }}</td>
                            <td>{{ $row['qty'] }}</td>
                            <td>{{ $row['diajukan_oleh'] }}</td>
                            <td>{{ $row['tgl_acc_finance'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($financeActivity === 'all' || $financeActivity === 'po')
            <h3>PO Terbit</h3>
            <table>
                <thead>
                    <tr>
                        <th>No PO</th>
                        <th>Supplier</th>
                        <th>Total Item</th>
                        <th>Tgl Terbit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($financeData['po_terbit'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['no_po'] }}</td>
                            <td>{{ $row['supplier'] }}</td>
                            <td>{{ $row['total_item'] }}</td>
                            <td>{{ $row['tgl_terbit'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    @endif

    @if ($showOperasional)
        <h2>Laporan Manager Operasional</h2>
        @if ($opsActivity === 'all' || $opsActivity === 'pr')
            <h3>Purchase Request yang Disetujui</h3>
            <table>
                <thead>
                    <tr>
                        <th>No PR</th>
                        <th>Material</th>
                        <th>Qty</th>
                        <th>Diajukan oleh</th>
                        <th>Status lanjut</th>
                        <th>Tgl ACC</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($opsData['pr_disetujui_manager_ops'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['no_pr'] }}</td>
                            <td>{{ $row['material'] }}</td>
                            <td>{{ $row['qty'] }}</td>
                            <td>{{ $row['diajukan_oleh'] }}</td>
                            <td>{{ $row['status_lanjut'] }}</td>
                            <td>{{ $row['tgl_acc'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($opsActivity === 'all' || $opsActivity === 'aging')
            <h3>Aging Material - Rekomendasi AI</h3>
            <table>
                <thead>
                    <tr>
                        <th>Nama Material</th>
                        <th>Kategori Aging</th>
                        <th>Rekomendasi AI</th>
                        <th>Status Validasi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($opsData['aging_rekomendasi_ai'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['nama_material'] }}</td>
                            <td>{{ $row['kategori_aging'] }}</td>
                            <td>{{ $row['rekomendasi_ai'] }}</td>
                            <td>{{ $row['status_validasi'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($opsActivity === 'all' || $opsActivity === 'material_rusak')
            <h3>Material Rusak (stok_rusak > 0)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama Material</th>
                        <th>Kategori</th>
                        <th>Stok Rusak</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($opsData['material_rusak'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['kode'] }}</td>
                            <td>{{ $row['nama_material'] }}</td>
                            <td>{{ $row['kategori'] }}</td>
                            <td>{{ $row['stok_rusak'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    @endif

    @if ($showTeknik)
        <h2>Laporan Manager Teknik</h2>
        @if ($teknikActivity === 'all' || $teknikActivity === 'project')
            <h3>Daftar Project</h3>
            <table>
                <thead>
                    <tr>
                        <th>Kode Project</th>
                        <th>Nama Project</th>
                        <th>Status</th>
                        <th>Tgl Mulai</th>
                        <th>Tgl Selesai</th>
                        <th>Jumlah Material</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($teknikData['daftar_project'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['kode_project'] }}</td>
                            <td>{{ $row['nama_project'] }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['tgl_mulai'] }}</td>
                            <td>{{ $row['tgl_selesai'] }}</td>
                            <td>{{ $row['material_count'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if (($teknikActivity === 'all' || $teknikActivity === 'outbound') && !empty($teknikData['outbound_per_project']))
            <h3>Outbound per Project (Grouped)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Kode Project</th>
                        <th>Nama Project</th>
                        <th>Jumlah Transaksi Outbound</th>
                        <th>Material (Total Qty)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (($teknikData['outbound_per_project'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['kode_project'] }}</td>
                            <td>{{ $row['nama_project'] }}</td>
                            <td>{{ $row['total_outbound'] }}</td>
                            <td>
                                @if (!empty($row['materials']))
                                    @foreach ($row['materials'] as $material)
                                        {{ $material['nama_material'] }}: {{ $material['qty'] }} {{ $material['satuan'] }}<br>
                                    @endforeach
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($showGudang)
        <h2>Laporan Staff Gudang</h2>

        @if ($gudangActivity === 'all' || $gudangActivity === 'ai_matching')
            <h3>Rekomendasi AI Matching</h3>
            <table>
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Project</th>
                        <th>Qty Rekomendasi</th>
                        <th>Rekomendasi Booking</th>
                        <th>Rekomendasi Generate PR</th>
                        <th>Stok Saat ACC</th>
                        <th>Status</th>
                        <th>Waktu ACC</th>
                        <th>Alasan Gemini</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($gudangData['rekomendasi_ai_matching'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['material'] }}</td>
                            <td>{{ $row['project'] }}</td>
                            <td>{{ $row['qty_rekomendasi'] }}</td>
                            <td>{{ $row['rekom_book'] ?? '-' }}</td>
                            <td>{{ $row['rekom_pr'] ?? '-' }}</td>
                            <td>{{ $row['stok_saat_acc'] ?? $row['stok_aktual'] }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['waktu_acc'] ?? '-' }}</td>
                            <td>{{ $row['alasan_gemini'] ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($gudangActivity === 'all' || $gudangActivity === 'task_operasional')
            <h3>Task Operasional (Inbound Manual + Alokasi Manual)</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipe</th>
                        <th>Judul</th>
                        <th>Untuk</th>
                        <th>Kebutuhan</th>
                        <th>Alokasi</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($gudangData['task_operasional'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td>{{ $row['tipe'] }}</td>
                            <td>{{ $row['judul'] }}</td>
                            <td>{{ $row['untuk'] }}</td>
                            <td>{{ $row['kebutuhan'] ?? '-' }}</td>
                            <td>{{ $row['alokasi'] ?? '-' }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['dibuat'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($gudangActivity === 'all' || $gudangActivity === 'stok')
            <h3>Laporan Stok Gudang</h3>
            <table>
                <thead>
                    <tr>
                        <th>Nama Material</th>
                        <th>Kode</th>
                        <th>Kategori</th>
                        <th>Stok Sekarang</th>
                        <th>Stok Minimum</th>
                        <th>Satuan</th>
                        <th>Status Stok</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($gudangData['laporan_stok_gudang'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['nama_material'] }}</td>
                            <td>{{ $row['kode'] }}</td>
                            <td>{{ $row['kategori'] }}</td>
                            <td>{{ $row['stok'] }}</td>
                            <td>{{ $row['stok_minimum'] }}</td>
                            <td>{{ $row['satuan'] }}</td>
                            <td>{{ $row['level'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($gudangActivity === 'all' || $gudangActivity === 'outbound')
            <h3>Outbound</h3>
            <table>
                <thead>
                    <tr>
                        <th>Tgl</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Material</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($gudangData['outbound'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['tgl'] }}</td>
                            <td>{{ $row['project'] }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>
                                @if (!empty($row['material_items']))
                                    @foreach (($row['material_items'] ?? []) as $material)
                                        {{ $material['nama_material'] ?? '-' }}: {{ $material['qty'] ?? '-' }} {{ $material['satuan'] ?? '-' }}<br>
                                    @endforeach
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($gudangActivity === 'all' || $gudangActivity === 'inbound')
            <h3>Inbound</h3>
            <table>
                <thead>
                    <tr>
                        <th>Tgl</th>
                        <th>No PO</th>
                        <th>Material</th>
                        <th>Qty</th>
                        <th>Sumber</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($gudangData['inbound'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['tgl'] }}</td>
                            <td>{{ $row['no_po'] }}</td>
                            <td>{{ $row['material'] }}</td>
                            <td>{{ $row['qty'] }}</td>
                            <td>{{ $row['sumber'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($gudangActivity === 'all' || $gudangActivity === 'booking')
            <h3>Booking Aktif</h3>
            <table>
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Project</th>
                        <th>Qty Booked</th>
                        <th>Tgl Booking</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($gudangData['booking_aktif'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['material'] }}</td>
                            <td>{{ $row['project'] }}</td>
                            <td>{{ $row['qty_booked'] }}</td>
                            <td>{{ $row['tgl_booking'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        @if ($gudangActivity === 'all' || $gudangActivity === 'retur')
            <h3>Retur</h3>
            <table>
                <thead>
                    <tr>
                        <th>Tgl</th>
                        <th>Material</th>
                        <th>Qty</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($gudangData['retur'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['tgl'] }}</td>
                            <td>{{ $row['material'] }}</td>
                            <td>{{ $row['qty'] }}</td>
                            <td>{{ $row['keterangan'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    @endif
</body>
</html>
