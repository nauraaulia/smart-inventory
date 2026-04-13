<x-filament-panels::page>
    <style>
        .laporan-operasional .table-wrap { border: 1px solid #e5e7eb; border-radius: 0.5rem; overflow-x: auto; }
        .laporan-operasional .table-head { background: #f3f4f6; color: #374151; }
        .laporan-operasional .table-body { background: #ffffff; color: #111827; }
        .laporan-operasional .table-row { border-top: 1px solid #e5e7eb; }

        .dark .laporan-operasional .table-wrap,
        .fi-theme-dark .laporan-operasional .table-wrap,
        [data-theme="dark"] .laporan-operasional .table-wrap {
            border-color: #374151 !important;
        }

        .dark .laporan-operasional .table-head,
        .fi-theme-dark .laporan-operasional .table-head,
        [data-theme="dark"] .laporan-operasional .table-head {
            background: #27272a !important;
            color: #e4e4e7 !important;
        }

        .dark .laporan-operasional .table-body,
        .fi-theme-dark .laporan-operasional .table-body,
        [data-theme="dark"] .laporan-operasional .table-body {
            background: #18181b !important;
            color: #f4f4f5 !important;
        }

        .dark .laporan-operasional .table-row,
        .fi-theme-dark .laporan-operasional .table-row,
        [data-theme="dark"] .laporan-operasional .table-row {
            border-color: #374151 !important;
        }

        .fi-theme-dark .laporan-operasional .bg-white,
        [data-theme="dark"] .laporan-operasional .bg-white {
            background-color: #18181b !important;
        }

        .dark .laporan-operasional .bg-white {
            background-color: #18181b !important;
        }

        .fi-theme-dark .laporan-operasional .text-gray-900,
        [data-theme="dark"] .laporan-operasional .text-gray-900 {
            color: #f9fafb !important;
        }

        .dark .laporan-operasional .text-gray-900 {
            color: #f9fafb !important;
        }

        .dark .laporan-operasional .border-gray-200,
        .fi-theme-dark .laporan-operasional .border-gray-200,
        [data-theme="dark"] .laporan-operasional .border-gray-200 {
            border-color: #374151 !important;
        }

        .dark .laporan-operasional .border-gray-300,
        .fi-theme-dark .laporan-operasional .border-gray-300,
        [data-theme="dark"] .laporan-operasional .border-gray-300 {
            border-color: #4b5563 !important;
        }

        .dark .laporan-operasional input,
        .dark .laporan-operasional select,
        .fi-theme-dark .laporan-operasional input,
        .fi-theme-dark .laporan-operasional select,
        [data-theme="dark"] .laporan-operasional input,
        [data-theme="dark"] .laporan-operasional select {
            background-color: #27272a !important;
            color: #f9fafb !important;
            border-color: #52525b !important;
        }

        .dark .laporan-operasional .text-gray-500,
        .dark .laporan-operasional .text-gray-400,
        .fi-theme-dark .laporan-operasional .text-gray-500,
        .fi-theme-dark .laporan-operasional .text-gray-400,
        [data-theme="dark"] .laporan-operasional .text-gray-500,
        [data-theme="dark"] .laporan-operasional .text-gray-400 {
            color: #d1d5db !important;
        }

        .laporan-operasional input[type="date"] {
            color-scheme: light;
        }

        .laporan-operasional .filter-card {
            margin-top: 1.5rem;
            padding: 1rem 1.25rem;
        }

        .laporan-operasional .filter-grid {
            display: flex;
            flex-wrap: wrap;
            row-gap: 0.75rem;
            column-gap: 0.85rem;
        }

        .laporan-operasional .filter-field {
            display: flex;
            flex: 1 1 260px;
            min-width: 220px;
            max-width: 340px;
            flex-direction: column;
            gap: 0.35rem;
        }

        .laporan-operasional .filter-card label {
            font-size: 0.875rem !important;
            line-height: 1.2 !important;
            color: #9ca3af !important;
            margin: 0 !important;
        }

        .laporan-operasional .filter-card input,
        .laporan-operasional .filter-card select {
            font-size: 0.875rem !important;
            line-height: 1.25rem !important;
            padding-top: 0.45rem !important;
            padding-bottom: 0.45rem !important;
        }

        .laporan-operasional .actions-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
        }

        .laporan-operasional .actions-row > * {
            margin: 0 !important;
        }

        .laporan-operasional .actions-row .fi-btn,
        .laporan-operasional .actions-row button {
            font-size: 0.875rem !important;
        }

        .dark .laporan-operasional input[type="date"],
        .fi-theme-dark .laporan-operasional input[type="date"],
        [data-theme="dark"] .laporan-operasional input[type="date"] {
            color-scheme: dark;
        }
    </style>

    @php
        $financeData = $roleReportData['finance'] ?? [];
        $opsData = $roleReportData['manager_operasional'] ?? [];
        $teknikData = $roleReportData['manager_teknik'] ?? [];
        $gudangData = $roleReportData['staff_administrasi_gudang'] ?? [];

        $financeActivity = (string) ($filters['finance_activity'] ?? 'all');
        $opsActivity = (string) ($filters['ops_activity'] ?? 'all');
        $teknikActivity = (string) ($filters['teknik_activity'] ?? 'all');
        $gudangActivity = (string) ($filters['gudang_activity'] ?? 'all');

        $statusBadge = static function (string $status): string {
            return match (strtolower($status)) {
                'approved', 'ordered', 'accepted', 'done', 'validated', 'sudah outbound', 'taken' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200',
                'pending', 'pending_finance', 'pending_manager', 'draft', 'ready' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
                'rejected', 'belum outbound' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200',
                default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
            };
        };
    @endphp

    <div class="laporan-operasional space-y-6">
        <div class="filter-card mx-4 sm:mx-6 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900 space-y-4">
            <div class="filter-grid grid sm:grid-cols-2 xl:grid-cols-3">
                <div class="filter-field">
                    <label class="text-[11px] font-medium text-gray-400">Dari Tanggal</label>
                    <input
                        type="date"
                        wire:model="filters.date_from"
                        class="w-full min-w-[170px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    />
                </div>
                <div class="filter-field">
                    <label class="text-[11px] font-medium text-gray-400">Sampai Tanggal</label>
                    <input
                        type="date"
                        wire:model="filters.date_to"
                        class="w-full min-w-[170px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    />
                </div>
            </div>

            <div class="filter-grid grid md:grid-cols-2 xl:grid-cols-3">
                @if ($this->canViewFinanceSection())
                    <div class="filter-field">
                        <label class="text-[11px] font-medium text-gray-400">Filter Kegiatan Finance</label>
                        <select wire:model="filters.finance_activity" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <option value="all">Semua Kegiatan</option>
                            <option value="pr">PR Disetujui Finance</option>
                            <option value="po">PO Terbit</option>
                        </select>
                    </div>
                @endif

                @if ($this->canViewOperasionalSection())
                    <div class="filter-field">
                        <label class="text-[11px] font-medium text-gray-400">Filter Kegiatan Operasional</label>
                        <select wire:model="filters.ops_activity" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <option value="all">Semua Kegiatan</option>
                            <option value="pr">PR Disetujui</option>
                            <option value="aging">Aging Material - Rekomendasi AI</option>
                            <option value="material_rusak">Material Rusak</option>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label class="text-[11px] font-medium text-gray-400">Status PR Manager Ops</label>
                        <select wire:model="filters.ops_pr_status" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <option value="all">Semua Status</option>
                            <option value="draft">draft</option>
                            <option value="pending_manager">pending_manager</option>
                            <option value="pending_finance">pending_finance</option>
                            <option value="approved">approved</option>
                            <option value="ordered">ordered</option>
                            <option value="received">received</option>
                            <option value="rejected">rejected</option>
                        </select>
                    </div>
                @endif

                @if ($this->canViewTeknikSection())
                    <div class="filter-field">
                        <label class="text-[11px] font-medium text-gray-400">Filter Kegiatan Manager Teknik</label>
                        <select wire:model="filters.teknik_activity" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <option value="all">Semua Kegiatan</option>
                            <option value="project">Daftar Project</option>
                            <option value="outbound">Rekap Outbound per Project</option>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label class="text-[11px] font-medium text-gray-400">Filter Template Project</label>
                        <select wire:model="filters.teknik_template_id" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            @foreach ($this->teknikTemplateOptions() as $templateId => $templateName)
                                <option value="{{ $templateId }}">{{ $templateName }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($this->canViewGudangSection())
                    <div class="filter-field">
                        <label class="text-[11px] font-medium text-gray-400">Filter Kegiatan Staff Gudang</label>
                        <select wire:model="filters.gudang_activity" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <option value="all">Semua Kegiatan</option>
                            <option value="ai_matching">Rekomendasi AI Matching</option>
                            <option value="task_operasional">Task Operasional</option>
                            <option value="stok">Laporan Stok Gudang</option>
                            <option value="outbound">Outbound</option>
                            <option value="inbound">Inbound</option>
                            <option value="booking">Booking Aktif</option>
                            <option value="retur">Retur</option>
                        </select>
                    </div>
                @endif
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700"></div>

            <div class="actions-row pt-1">
                <x-filament::button size="sm" color="primary" wire:click="applyFilters">Apply Filter</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="exportPdf">Export PDF</x-filament::button>
                <x-filament::button size="sm" color="success" wire:click="exportExcel">Export Excel</x-filament::button>
            </div>
        </div>

        @if ($this->canViewFinanceSection())
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Laporan Finance</h3>

                @if ($financeActivity === 'all' || $financeActivity === 'pr')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">PR Disetujui Finance</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left">No PR</th><th class="px-3 py-2 text-left">Material</th><th class="px-3 py-2 text-left">Qty</th><th class="px-3 py-2 text-left">Diajukan Oleh</th><th class="px-3 py-2 text-left">Tgl ACC Finance</th></tr></thead>
                                <tbody class="table-body">
                                    @forelse (($financeData['pr_disetujui_finance'] ?? []) as $row)
                                        <tr class="table-row"><td class="px-3 py-2">{{ $row['no_pr'] }}</td><td class="px-3 py-2">{{ $row['material'] }}</td><td class="px-3 py-2">{{ $row['qty'] }}</td><td class="px-3 py-2">{{ $row['diajukan_oleh'] }}</td><td class="px-3 py-2">{{ $row['tgl_acc_finance'] }}</td></tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if ($financeActivity === 'all' || $financeActivity === 'po')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">PO Terbit</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left">No PO</th><th class="px-3 py-2 text-left">Supplier</th><th class="px-3 py-2 text-left">Total Item</th><th class="px-3 py-2 text-left">Tgl Terbit</th></tr></thead>
                                <tbody class="table-body">
                                    @forelse (($financeData['po_terbit'] ?? []) as $row)
                                        <tr class="table-row"><td class="px-3 py-2">{{ $row['no_po'] }}</td><td class="px-3 py-2">{{ $row['supplier'] }}</td><td class="px-3 py-2">{{ $row['total_item'] }}</td><td class="px-3 py-2">{{ $row['tgl_terbit'] }}</td></tr>
                                    @empty
                                        <tr><td colspan="4" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if ($this->canViewOperasionalSection())
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Laporan Manager Operasional</h3>

                @if ($opsActivity === 'all' || $opsActivity === 'pr')
                <div>
                    <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Purchase Request yang Disetujui</h4>
                    <div class="table-wrap">
                        <table class="min-w-full text-sm">
                            <thead class="table-head">
                                <tr>
                                    <th class="px-3 py-2 text-left w-10"></th>
                                    <th class="px-3 py-2 text-left">No PR</th>
                                    <th class="px-3 py-2 text-left">Material</th>
                                    <th class="px-3 py-2 text-left">Qty</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                    <th class="px-3 py-2 text-left">Tgl ACC</th>
                                </tr>
                            </thead>
                            <tbody class="table-body">
                                @forelse (($opsData['pr_disetujui_manager_ops'] ?? []) as $pr)
                                    <tr class="table-row">
                                        <td class="px-3 py-2">
                                            <button type="button" wire:click="toggleExpand({{ (int) $pr['id'] }})" class="inline-flex items-center justify-center rounded p-1 text-gray-700 dark:text-gray-200">
                                                @if (in_array((int) $pr['id'], $this->expandedRows, true))
                                                    <x-heroicon-o-chevron-down class="h-4 w-4" />
                                                @else
                                                    <x-heroicon-o-chevron-right class="h-4 w-4" />
                                                @endif
                                            </button>
                                        </td>
                                        <td class="px-3 py-2">{{ $pr['no_pr'] }}</td>
                                        <td class="px-3 py-2">{{ $pr['material'] }}</td>
                                        <td class="px-3 py-2">{{ $pr['qty'] }}</td>
                                        <td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge((string) $pr['status_lanjut']) }}">{{ $pr['status_lanjut'] }}</span></td>
                                        <td class="px-3 py-2">{{ $pr['tgl_acc'] }}</td>
                                    </tr>
                                    @if (in_array((int) $pr['id'], $this->expandedRows, true))
                                        <tr class="table-row bg-gray-50 dark:bg-gray-800/40">
                                            <td></td>
                                            <td colspan="5" class="px-3 py-3">
                                                <div class="table-wrap">
                                                    <table class="min-w-full text-xs">
                                                        <thead class="table-head">
                                                            <tr>
                                                                <th class="px-2 py-1 text-left">Nama Material</th>
                                                                <th class="px-2 py-1 text-left">Qty</th>
                                                                <th class="px-2 py-1 text-left">Satuan</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="table-body">
                                                            @foreach (($pr['items'] ?? []) as $item)
                                                                <tr class="table-row">
                                                                    <td class="px-2 py-1">{{ $item['nama_material'] ?? '-' }}</td>
                                                                    <td class="px-2 py-1">{{ $item['qty'] ?? '-' }}</td>
                                                                    <td class="px-2 py-1">{{ $item['satuan'] ?? '-' }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr><td colspan="6" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                @if ($opsActivity === 'all' || $opsActivity === 'aging')
                <div>
                    <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Aging Material - Rekomendasi AI</h4>
                    <div class="table-wrap">
                        <table class="min-w-full text-sm">
                            <thead class="table-head"><tr><th class="px-3 py-2 text-left">Nama Material</th><th class="px-3 py-2 text-left">Kategori Aging</th><th class="px-3 py-2 text-left">Rekomendasi AI</th><th class="px-3 py-2 text-left">Status Validasi</th></tr></thead>
                            <tbody class="table-body">
                                @forelse (($opsData['aging_rekomendasi_ai'] ?? []) as $row)
                                    <tr class="table-row"><td class="px-3 py-2">{{ $row['nama_material'] }}</td><td class="px-3 py-2">{{ $row['kategori_aging'] }}</td><td class="px-3 py-2">{{ $row['rekomendasi_ai'] }}</td><td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge((string) $row['status_validasi']) }}">{{ $row['status_validasi'] }}</span></td></tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                @if ($opsActivity === 'all' || $opsActivity === 'material_rusak')
                <div>
                    <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Material Rusak (stok_rusak &gt; 0)</h4>
                    <div class="table-wrap">
                        <table class="min-w-full text-sm">
                            <thead class="table-head"><tr><th class="px-3 py-2 text-left">Kode</th><th class="px-3 py-2 text-left">Nama Material</th><th class="px-3 py-2 text-left">Kategori</th><th class="px-3 py-2 text-left">Stok Rusak</th></tr></thead>
                            <tbody class="table-body">
                                @forelse (($opsData['material_rusak'] ?? []) as $row)
                                    <tr class="table-row"><td class="px-3 py-2">{{ $row['kode'] }}</td><td class="px-3 py-2">{{ $row['nama_material'] }}</td><td class="px-3 py-2">{{ $row['kategori'] }}</td><td class="px-3 py-2">{{ $row['stok_rusak'] }}</td></tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
            </div>
        @endif

        @if ($this->canViewTeknikSection())
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Laporan Manager Teknik</h3>

                @if ($teknikActivity === 'all' || $teknikActivity === 'project')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Daftar Project</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left w-16">Aksi</th><th class="px-3 py-2 text-left">Kode Project</th><th class="px-3 py-2 text-left">Nama Project</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Tgl Mulai</th><th class="px-3 py-2 text-left">Tgl Selesai</th><th class="px-3 py-2 text-left">Jumlah Material</th></tr></thead>
                                <tbody class="table-body">
                                    @forelse (($teknikData['daftar_project'] ?? []) as $row)
                                        <tr class="table-row">
                                            <td class="px-3 py-2">
                                                <button type="button" wire:click="toggleProjectExpand({{ (int) $row['project_id'] }})" class="inline-flex items-center gap-1 rounded px-1 py-0.5 text-gray-700 dark:text-gray-200" title="Tampilkan detail material">
                                                    @if (in_array((int) $row['project_id'], $this->expandedProjectRows, true))
                                                        <x-heroicon-o-minus-circle class="h-4 w-4" />
                                                        <span class="text-xs font-semibold">-</span>
                                                    @else
                                                        <x-heroicon-o-plus-circle class="h-4 w-4" />
                                                        <span class="text-xs font-semibold">+</span>
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-3 py-2">{{ $row['kode_project'] }}</td>
                                            <td class="px-3 py-2">{{ $row['nama_project'] }}</td>
                                            <td class="px-3 py-2">{{ $row['status'] }}</td>
                                            <td class="px-3 py-2">{{ $row['tgl_mulai'] }}</td>
                                            <td class="px-3 py-2">{{ $row['tgl_selesai'] }}</td>
                                            <td class="px-3 py-2">{{ $row['material_count'] }}</td>
                                        </tr>
                                        @if (in_array((int) $row['project_id'], $this->expandedProjectRows, true))
                                            <tr class="table-row bg-gray-50 dark:bg-gray-800/40">
                                                <td></td>
                                                <td colspan="6" class="px-3 py-3">
                                                    <div class="table-wrap">
                                                        <table class="min-w-full text-xs">
                                                            <thead class="table-head"><tr><th class="px-2 py-1 text-left">Nama Material</th><th class="px-2 py-1 text-left">Qty</th><th class="px-2 py-1 text-left">Satuan</th></tr></thead>
                                                            <tbody class="table-body">
                                                                @forelse (($row['materials'] ?? []) as $material)
                                                                    <tr class="table-row">
                                                                        <td class="px-2 py-1">{{ $material['nama_material'] ?? '-' }}</td>
                                                                        <td class="px-2 py-1">{{ $material['qty'] ?? '-' }}</td>
                                                                        <td class="px-2 py-1">{{ $material['satuan'] ?? '-' }}</td>
                                                                    </tr>
                                                                @empty
                                                                    <tr><td colspan="3" class="px-2 py-2 text-center text-gray-500 dark:text-gray-400">Tidak ada material.</td></tr>
                                                                @endforelse
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr><td colspan="7" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if (($teknikActivity === 'all' || $teknikActivity === 'outbound') && !empty($teknikData['outbound_per_project']))
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Rekap Outbound per Project</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left w-10"></th><th class="px-3 py-2 text-left">Kode Project</th><th class="px-3 py-2 text-left">Nama Project</th><th class="px-3 py-2 text-left">Jumlah Transaksi</th><th class="px-3 py-2 text-left">Jumlah Material</th></tr></thead>
                                <tbody class="table-body">
                                    @foreach (($teknikData['outbound_per_project'] ?? []) as $row)
                                        <tr class="table-row">
                                            <td class="px-3 py-2">
                                                <button type="button" wire:click="toggleOutboundProjectExpand({{ (int) $row['project_id'] }})" class="inline-flex items-center rounded p-0.5 text-gray-700 dark:text-gray-200">
                                                    @if (in_array((int) $row['project_id'], $this->expandedOutboundProjectRows, true))
                                                        <x-heroicon-o-plus-small class="h-4 w-4 mr-1 rotate-45" />
                                                    @else
                                                        <x-heroicon-o-plus-small class="h-4 w-4 mr-1" />
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-3 py-2">{{ $row['kode_project'] }}</td>
                                            <td class="px-3 py-2">{{ $row['nama_project'] }}</td>
                                            <td class="px-3 py-2">{{ $row['total_outbound'] }}</td>
                                            <td class="px-3 py-2">{{ $row['material_count'] }}</td>
                                        </tr>
                                        @if (in_array((int) $row['project_id'], $this->expandedOutboundProjectRows, true))
                                            <tr class="table-row bg-gray-50 dark:bg-gray-800/40">
                                                <td></td>
                                                <td colspan="4" class="px-3 py-3">
                                                    <div class="table-wrap">
                                                        <table class="min-w-full text-xs">
                                                            <thead class="table-head"><tr><th class="px-2 py-1 text-left">Nama Material</th><th class="px-2 py-1 text-left">Qty</th><th class="px-2 py-1 text-left">Satuan</th></tr></thead>
                                                            <tbody class="table-body">
                                                                @forelse (($row['materials'] ?? []) as $material)
                                                                    <tr class="table-row">
                                                                        <td class="px-2 py-1">{{ $material['nama_material'] ?? '-' }}</td>
                                                                        <td class="px-2 py-1">{{ $material['qty'] ?? '-' }}</td>
                                                                        <td class="px-2 py-1">{{ $material['satuan'] ?? '-' }}</td>
                                                                    </tr>
                                                                @empty
                                                                    <tr><td colspan="3" class="px-2 py-2 text-center text-gray-500 dark:text-gray-400">Tidak ada material outbound.</td></tr>
                                                                @endforelse
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if ($this->canViewGudangSection())
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 space-y-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Laporan Staff Gudang</h3>

                @if ($gudangActivity === 'all' || $gudangActivity === 'ai_matching')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Rekomendasi AI Matching</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left w-16">Aksi</th><th class="px-3 py-2 text-left">Material</th><th class="px-3 py-2 text-left">Project</th><th class="px-3 py-2 text-left">Qty Rekomendasi</th><th class="px-3 py-2 text-left">Rekomendasi Booking</th><th class="px-3 py-2 text-left">Rekomendasi Generate PR</th><th class="px-3 py-2 text-left">Stok Saat ACC</th><th class="px-3 py-2 text-left">Status</th></tr></thead>
                                <tbody class="table-body">
                                    @forelse (($gudangData['rekomendasi_ai_matching'] ?? []) as $row)
                                        <tr class="table-row">
                                            <td class="px-3 py-2">
                                                <button type="button" wire:click="toggleAiMatchingExpand('{{ (string) ($row['row_key'] ?? '') }}')" class="inline-flex items-center gap-1 rounded px-1 py-0.5 text-gray-700 dark:text-gray-200" title="Tampilkan detail Gemini">
                                                    @if (in_array((string) ($row['row_key'] ?? ''), $this->expandedAiMatchingRows, true))
                                                        <x-heroicon-o-minus-circle class="h-4 w-4" />
                                                        <span class="text-xs font-semibold">-</span>
                                                    @else
                                                        <x-heroicon-o-plus-circle class="h-4 w-4" />
                                                        <span class="text-xs font-semibold">+</span>
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-3 py-2">{{ $row['material'] }}</td>
                                            <td class="px-3 py-2">{{ $row['project'] }}</td>
                                            <td class="px-3 py-2">{{ $row['qty_rekomendasi'] }}</td>
                                            <td class="px-3 py-2">{{ $row['rekom_book'] ?? '-' }}</td>
                                            <td class="px-3 py-2">{{ $row['rekom_pr'] ?? '-' }}</td>
                                            <td class="px-3 py-2">{{ $row['stok_saat_acc'] ?? $row['stok_aktual'] }}</td>
                                            <td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge((string) $row['status']) }}">{{ $row['status'] }}</span></td>
                                        </tr>
                                        @if (in_array((string) ($row['row_key'] ?? ''), $this->expandedAiMatchingRows, true))
                                            <tr class="table-row bg-gray-50 dark:bg-gray-800/30">
                                                <td></td>
                                                <td colspan="7" class="px-3 py-3">
                                                    <div class="grid gap-2 md:grid-cols-2 text-xs">
                                                        <div>
                                                            <p class="font-semibold text-gray-900 dark:text-white">Waktu ACC</p>
                                                            <p class="text-gray-700 dark:text-gray-300">{{ $row['waktu_acc'] ?? '-' }}</p>
                                                        </div>
                                                        <div>
                                                            <p class="font-semibold text-gray-900 dark:text-white">Stok Saat ACC</p>
                                                            <p class="text-gray-700 dark:text-gray-300">{{ $row['stok_saat_acc'] ?? '-' }}</p>
                                                        </div>
                                                        <div class="md:col-span-2">
                                                            <p class="font-semibold text-gray-900 dark:text-white">Alasan Gemini</p>
                                                            <p class="text-gray-700 dark:text-gray-300">{{ $row['alasan_gemini'] ?? '-' }}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr><td colspan="8" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if ($gudangActivity === 'all' || $gudangActivity === 'task_operasional')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Task Operasional (Inbound Manual + Alokasi Manual)</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left">ID</th><th class="px-3 py-2 text-left">Tipe</th><th class="px-3 py-2 text-left">Judul</th><th class="px-3 py-2 text-left">Untuk</th><th class="px-3 py-2 text-left">Kebutuhan</th><th class="px-3 py-2 text-left">Alokasi</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Dibuat</th></tr></thead>
                                <tbody class="table-body">
                                    @forelse (($gudangData['task_operasional'] ?? []) as $row)
                                        <tr class="table-row"><td class="px-3 py-2">{{ $row['id'] }}</td><td class="px-3 py-2">{{ $row['tipe'] }}</td><td class="px-3 py-2">{{ $row['judul'] }}</td><td class="px-3 py-2">{{ $row['untuk'] }}</td><td class="px-3 py-2">{{ $row['kebutuhan'] ?? '-' }}</td><td class="px-3 py-2">{{ $row['alokasi'] ?? '-' }}</td><td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge((string) $row['status']) }}">{{ $row['status'] }}</span></td><td class="px-3 py-2">{{ $row['dibuat'] }}</td></tr>
                                    @empty
                                        <tr><td colspan="8" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada task operasional.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if ($gudangActivity === 'all' || $gudangActivity === 'stok')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Laporan Stok Gudang</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left">Nama Material</th><th class="px-3 py-2 text-left">Kode</th><th class="px-3 py-2 text-left">Kategori</th><th class="px-3 py-2 text-left">Stok</th><th class="px-3 py-2 text-left">Min Stok</th><th class="px-3 py-2 text-left">Satuan</th><th class="px-3 py-2 text-left">Status</th></tr></thead>
                                <tbody class="table-body">
                                    @forelse (($gudangData['laporan_stok_gudang'] ?? []) as $row)
                                        <tr class="table-row">
                                            <td class="px-3 py-2">{{ $row['nama_material'] }}</td>
                                            <td class="px-3 py-2">{{ $row['kode'] }}</td>
                                            <td class="px-3 py-2">{{ $row['kategori'] }}</td>
                                            <td class="px-3 py-2">{{ $row['stok'] }}</td>
                                            <td class="px-3 py-2">{{ $row['stok_minimum'] }}</td>
                                            <td class="px-3 py-2">{{ $row['satuan'] }}</td>
                                            <td class="px-3 py-2">
                                                @if (($row['level'] ?? 'normal') === 'critical')
                                                    <span class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-900/40 dark:text-rose-200">Hampir Habis</span>
                                                @elseif (($row['level'] ?? 'normal') === 'warning')
                                                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-200">Mendekati Batas</span>
                                                @else
                                                    <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">Aman</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Data stok tidak tersedia.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if ($gudangActivity === 'all' || $gudangActivity === 'outbound')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Outbound</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm">
                                <thead class="table-head"><tr><th class="px-3 py-2 text-left w-16">Aksi</th><th class="px-3 py-2 text-left">Tgl</th><th class="px-3 py-2 text-left">Project</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Jumlah Material</th></tr></thead>
                                <tbody class="table-body">
                                    @forelse (($gudangData['outbound'] ?? []) as $row)
                                        <tr class="table-row">
                                            <td class="px-3 py-2">
                                                <button type="button" wire:click="toggleOutboundExpand({{ (int) ($row['id'] ?? 0) }})" class="inline-flex items-center gap-1 rounded px-1 py-0.5 text-gray-700 dark:text-gray-200" title="Tampilkan detail material outbound">
                                                    @if (in_array((int) ($row['id'] ?? 0), $this->expandedOutboundRows, true))
                                                        <x-heroicon-o-minus-circle class="h-4 w-4" />
                                                        <span class="text-xs font-semibold">-</span>
                                                    @else
                                                        <x-heroicon-o-plus-circle class="h-4 w-4" />
                                                        <span class="text-xs font-semibold">+</span>
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-3 py-2">{{ $row['tgl'] }}</td>
                                            <td class="px-3 py-2">{{ $row['project'] }}</td>
                                            <td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge((string) $row['status']) }}">{{ $row['status'] }}</span></td>
                                            <td class="px-3 py-2">{{ $row['material_count'] }}</td>
                                        </tr>
                                        @if (in_array((int) ($row['id'] ?? 0), $this->expandedOutboundRows, true))
                                            <tr class="table-row bg-gray-50 dark:bg-gray-800/40">
                                                <td></td>
                                                <td colspan="4" class="px-3 py-3">
                                                    <div class="table-wrap">
                                                        <table class="min-w-full text-xs">
                                                            <thead class="table-head"><tr><th class="px-2 py-1 text-left">Material</th><th class="px-2 py-1 text-left">Qty</th><th class="px-2 py-1 text-left">Satuan</th></tr></thead>
                                                            <tbody class="table-body">
                                                                @forelse (($row['material_items'] ?? []) as $materialItem)
                                                                    <tr class="table-row"><td class="px-2 py-1">{{ $materialItem['nama_material'] ?? '-' }}</td><td class="px-2 py-1">{{ $materialItem['qty'] ?? '-' }}</td><td class="px-2 py-1">{{ $materialItem['satuan'] ?? '-' }}</td></tr>
                                                                @empty
                                                                    <tr><td colspan="3" class="px-2 py-2 text-center text-gray-500 dark:text-gray-400">Tidak ada rincian material.</td></tr>
                                                                @endforelse
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data outbound.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if ($gudangActivity === 'all' || $gudangActivity === 'inbound')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Inbound</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm"><thead class="table-head"><tr><th class="px-3 py-2 text-left">Tgl</th><th class="px-3 py-2 text-left">No PO</th><th class="px-3 py-2 text-left">Material</th><th class="px-3 py-2 text-left">Qty</th><th class="px-3 py-2 text-left">Sumber</th></tr></thead><tbody class="table-body">@forelse (($gudangData['inbound'] ?? []) as $row)<tr class="table-row"><td class="px-3 py-2">{{ $row['tgl'] }}</td><td class="px-3 py-2">{{ $row['no_po'] }}</td><td class="px-3 py-2">{{ $row['material'] }}</td><td class="px-3 py-2">{{ $row['qty'] }}</td><td class="px-3 py-2">{{ $row['sumber'] }}</td></tr>@empty<tr><td colspan="5" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>@endforelse</tbody></table>
                        </div>
                    </div>
                @endif

                @if ($gudangActivity === 'all' || $gudangActivity === 'booking')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Booking Aktif</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm"><thead class="table-head"><tr><th class="px-3 py-2 text-left">Material</th><th class="px-3 py-2 text-left">Project</th><th class="px-3 py-2 text-left">Qty Booked</th><th class="px-3 py-2 text-left">Tgl Booking</th></tr></thead><tbody class="table-body">@forelse (($gudangData['booking_aktif'] ?? []) as $row)<tr class="table-row"><td class="px-3 py-2">{{ $row['material'] }}</td><td class="px-3 py-2">{{ $row['project'] }}</td><td class="px-3 py-2">{{ $row['qty_booked'] }}</td><td class="px-3 py-2">{{ $row['tgl_booking'] }}</td></tr>@empty<tr><td colspan="4" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>@endforelse</tbody></table>
                        </div>
                    </div>
                @endif

                @if ($gudangActivity === 'all' || $gudangActivity === 'retur')
                    <div>
                        <h4 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Retur</h4>
                        <div class="table-wrap">
                            <table class="min-w-full text-sm"><thead class="table-head"><tr><th class="px-3 py-2 text-left">Tgl</th><th class="px-3 py-2 text-left">Material</th><th class="px-3 py-2 text-left">Qty</th><th class="px-3 py-2 text-left">Keterangan</th></tr></thead><tbody class="table-body">@forelse (($gudangData['retur'] ?? []) as $row)<tr class="table-row"><td class="px-3 py-2">{{ $row['tgl'] }}</td><td class="px-3 py-2">{{ $row['material'] }}</td><td class="px-3 py-2">{{ $row['qty'] }}</td><td class="px-3 py-2">{{ $row['keterangan'] }}</td></tr>@empty<tr><td colspan="4" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">Tidak ada data.</td></tr>@endforelse</tbody></table>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
