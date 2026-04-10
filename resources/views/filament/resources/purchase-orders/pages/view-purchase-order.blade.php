<x-filament-panels::page>
    @php
        /** @var \App\Models\PurchaseOrder $record */
        $record = $this->getRecord();
        $rows = $this->getItemRows();
        $progressPercent = $this->getProgressPercent();

        $statusColorClass = match ($this->getPoStatusColor()) {
            'success' => 'border-emerald-300 bg-emerald-100 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-200',
            'info' => 'border-sky-300 bg-sky-100 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/20 dark:text-sky-200',
            'warning' => 'border-amber-300 bg-amber-100 text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/20 dark:text-amber-200',
            default => 'border-zinc-300 bg-zinc-100 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700/30 dark:text-zinc-200',
        };
    @endphp

    <style>
        .po-shell {
            background: #ffffff;
            border-color: rgb(228 228 231);
        }

        .po-card {
            background: rgb(250 250 250);
            border-color: rgb(228 228 231);
        }

        .po-head {
            background: rgb(244 244 245);
            border-color: rgb(228 228 231);
            color: rgb(82 82 91);
        }

        .po-table-row {
            background: #ffffff;
        }

        .fi-theme-dark .po-shell,
        .dark .po-shell {
            background: rgb(24 24 27) !important;
            border-color: rgb(255 255 255 / 0.10) !important;
        }

        .fi-theme-dark .po-card,
        .dark .po-card {
            background: rgb(39 39 42) !important;
            border-color: rgb(255 255 255 / 0.10) !important;
        }

        .fi-theme-dark .po-head,
        .dark .po-head {
            background: rgb(39 39 42) !important;
            border-color: rgb(255 255 255 / 0.10) !important;
            color: rgb(203 213 225) !important;
        }

        .fi-theme-dark .po-table-row,
        .dark .po-table-row {
            background: rgb(24 24 27) !important;
        }
    </style>

    <div class="mx-auto w-full max-w-6xl space-y-6">
        <section class="po-shell rounded-2xl border p-6 shadow-sm">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="po-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Nomor PO</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->po_number }}</div>
                </div>
                <div class="po-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Supplier</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->supplier?->nama_supplier ?? '-' }}</div>
                </div>
                <div class="po-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Tanggal PO</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->po_date?->format('d M Y') ?? '-' }}</div>
                </div>
                <div class="po-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Status</div>
                    <div class="mt-2">
                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $statusColorClass }}">{{ $record->progress_status }}</span>
                    </div>
                </div>
                <div class="po-card rounded-xl border p-4 md:col-span-2">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Catatan</div>
                    <div class="mt-2 text-sm text-zinc-900 dark:text-zinc-100">{{ filled($record->notes) ? $record->notes : '-' }}</div>
                </div>
            </div>
        </section>

        <section class="po-shell rounded-2xl border p-6 shadow-sm">
            <div class="mb-4 space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Progress Penerimaan</h2>
                <span class="inline-flex rounded-full border border-sky-300 bg-sky-100 px-4 py-1.5 text-xs font-semibold tracking-wide text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/20 dark:text-sky-200">{{ $this->getCompletedItemSummary() }}</span>
            </div>
            <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-3 rounded-full bg-emerald-500" style="width: {{ $progressPercent }}%"></div>
            </div>
            <div class="mt-3 text-xs font-medium tracking-wide text-zinc-500 dark:text-zinc-300">{{ $progressPercent }}% qty pesanan sudah diterima.</div>
        </section>

        <section class="po-shell rounded-2xl border shadow-sm">
            <div class="po-head border-b px-6 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide">Detail Item Purchase Order</h2>
            </div>

            @if (empty($rows))
                <div class="px-6 py-10 text-sm text-zinc-600 dark:text-zinc-300">Belum ada item pada PO ini.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="po-head text-left text-xs font-semibold uppercase tracking-wide">
                            <tr>
                                <th class="px-6 py-3">Nama Material</th>
                                <th class="px-6 py-3">Qty Pesan</th>
                                <th class="px-6 py-3">Qty Diterima</th>
                                <th class="px-6 py-3">Sisa</th>
                                <th class="px-6 py-3">Unit</th>
                                <th class="px-6 py-3">Status Item</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach ($rows as $row)
                                @php
                                    $itemBadge = match ($row['status']) {
                                        'Completed' => 'border-emerald-300 bg-emerald-100 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-200',
                                        'Partial' => 'border-sky-300 bg-sky-100 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/20 dark:text-sky-200',
                                        default => 'border-amber-300 bg-amber-100 text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/20 dark:text-amber-200',
                                    };
                                @endphp
                                <tr class="po-table-row">
                                    <td class="px-6 py-4 font-medium text-zinc-900 dark:text-zinc-100">{{ $row['material_name'] }}</td>
                                    <td class="px-6 py-4 text-zinc-800 dark:text-zinc-200">{{ number_format((float) $row['qty_order'], 2, '.', '') }}</td>
                                    <td class="px-6 py-4 text-zinc-800 dark:text-zinc-200">{{ number_format((float) $row['qty_received'], 2, '.', '') }}</td>
                                    <td class="px-6 py-4 text-zinc-800 dark:text-zinc-200">{{ number_format((float) $row['qty_remaining'], 2, '.', '') }}</td>
                                    <td class="px-6 py-4 text-zinc-800 dark:text-zinc-200">{{ $row['unit'] }}</td>
                                    <td class="px-6 py-4"><span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $itemBadge }}">{{ $row['status'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
