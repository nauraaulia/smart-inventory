<x-filament-panels::page>
    @php
        /** @var \App\Models\PurchaseRequest $record */
        $record = $this->getRecord();
        $timeline = $this->getApprovalTimeline();

        $statusLabel = ucfirst(str_replace('_', ' ', (string) $record->status));
        $statusColorClass = match ($this->getStatusBadgeColor()) {
            'danger' => 'border-rose-300 bg-rose-100 text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/20 dark:text-rose-200',
            'warning' => 'border-amber-300 bg-amber-100 text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/20 dark:text-amber-200',
            'success' => 'border-emerald-300 bg-emerald-100 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-200',
            'info' => 'border-sky-300 bg-sky-100 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/20 dark:text-sky-200',
            default => 'border-zinc-300 bg-zinc-100 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700/30 dark:text-zinc-200',
        };

        $priorityColorClass = match ($this->getPriorityBadgeColor()) {
            'danger' => 'border-rose-300 bg-rose-100 text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/20 dark:text-rose-200',
            'warning' => 'border-orange-300 bg-orange-100 text-orange-700 dark:border-orange-500/40 dark:bg-orange-500/20 dark:text-orange-200',
            'success' => 'border-emerald-300 bg-emerald-100 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-200',
            default => 'border-sky-300 bg-sky-100 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/20 dark:text-sky-200',
        };

        $qtyText = number_format((float) $record->quantity, 2, '.', '') . ' ' . ($record->material?->satuan ?: 'Unit');
    @endphp

    <style>
        .pr-shell {
            background: #ffffff;
            border-color: rgb(228 228 231);
        }

        .pr-card {
            background: rgb(250 250 250);
            border-color: rgb(228 228 231);
        }

        .pr-head {
            background: rgb(244 244 245);
            border-color: rgb(228 228 231);
            color: rgb(82 82 91);
        }

        .pr-table-row {
            background: #ffffff;
        }

        .fi-theme-dark .pr-shell,
        .dark .pr-shell {
            background: rgb(24 24 27) !important;
            border-color: rgb(255 255 255 / 0.10) !important;
        }

        .fi-theme-dark .pr-card,
        .dark .pr-card {
            background: rgb(39 39 42) !important;
            border-color: rgb(255 255 255 / 0.10) !important;
        }

        .fi-theme-dark .pr-head,
        .dark .pr-head {
            background: rgb(39 39 42) !important;
            border-color: rgb(255 255 255 / 0.10) !important;
            color: rgb(203 213 225) !important;
        }

        .fi-theme-dark .pr-table-row,
        .dark .pr-table-row {
            background: rgb(24 24 27) !important;
        }

        .timeline-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: center;
        }

        @media (max-width: 768px) {
            .timeline-row {
                grid-template-columns: 1fr;
                gap: 0.375rem;
            }

            .timeline-time {
                text-align: left !important;
                padding-left: 1.55rem;
            }
        }
    </style>

    <div class="mx-auto w-full max-w-6xl space-y-6">
        <section class="pr-shell rounded-2xl border p-6 shadow-sm">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="pr-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Kode Proyek</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->project?->kode_proyek ?? '-' }}</div>
                </div>
                <div class="pr-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Material</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->material?->nama_material ?? '-' }}</div>
                </div>
                <div class="pr-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Qty</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $qtyText }}</div>
                </div>
                <div class="pr-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Status</div>
                    <div class="mt-2">
                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $statusColorClass }}">{{ $statusLabel }}</span>
                    </div>
                </div>
                <div class="pr-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Prioritas</div>
                    <div class="mt-2">
                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $priorityColorClass }}">{{ $this->getPriorityLabel() }}</span>
                    </div>
                </div>
                <div class="pr-card rounded-xl border p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Pembuat</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ ($record->requester?->id_custom ? $record->requester->id_custom . ' - ' : '') . ($record->requester?->name ?? '-') }}</div>
                </div>
                <div class="pr-card rounded-xl border p-4 md:col-span-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Tanggal</div>
                    <div class="mt-2 text-sm font-medium text-zinc-800 dark:text-zinc-100">
                        Draft: {{ $record->created_at?->format('d M Y H:i') ?? '-' }}
                        <span class="mx-2">|</span>
                        Submit: {{ $record->submitted_at?->format('d M Y H:i') ?? '-' }}
                        <span class="mx-2">|</span>
                        Dibutuhkan: {{ $record->needed_date?->format('d M Y') ?? '-' }}
                    </div>
                </div>
            </div>
        </section>

        <section class="pr-shell rounded-2xl border shadow-sm">
            <div class="pr-head border-b px-6 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide">Detail Item PR</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="pr-head text-left text-xs font-semibold uppercase tracking-wide">
                        <tr>
                            <th class="px-6 py-3">Kode Proyek</th>
                            <th class="px-6 py-3">Nama Material</th>
                            <th class="px-6 py-3">Qty</th>
                            <th class="px-6 py-3">Prioritas</th>
                            <th class="px-6 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                        <tr class="pr-table-row">
                            <td class="px-6 py-4 text-zinc-900 dark:text-zinc-100">{{ $record->project?->kode_proyek ?? '-' }}</td>
                            <td class="px-6 py-4 text-zinc-900 dark:text-zinc-100">{{ $record->material?->nama_material ?? '-' }}</td>
                            <td class="px-6 py-4 text-zinc-800 dark:text-zinc-200">{{ $qtyText }}</td>
                            <td class="px-6 py-4"><span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $priorityColorClass }}">{{ $this->getPriorityLabel() }}</span></td>
                            <td class="px-6 py-4"><span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $statusColorClass }}">{{ $statusLabel }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pr-shell rounded-2xl border shadow-sm">
            <div class="pr-head border-b px-6 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide">Timeline Approval</h2>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-white/10">
                @foreach ($timeline as $row)
                    @php
                        $dotClass = match ($row['state']) {
                            'done' => 'bg-emerald-500',
                            'rejected' => 'bg-rose-500',
                            default => 'bg-zinc-400',
                        };
                    @endphp
                    <div class="timeline-row px-6 py-4">
                        <div class="flex items-start gap-3">
                            <span class="h-2.5 w-2.5 rounded-full {{ $dotClass }}"></span>
                            <div>
                                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $row['label'] }}</div>
                                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $row['actor'] }}</div>
                            </div>
                        </div>
                        <div class="timeline-time text-xs font-medium text-zinc-600 dark:text-zinc-300 text-right">{{ $row['at'] }}</div>
                    </div>
                @endforeach
            </div>
            @if (filled($record->rejection_reason))
                <div class="border-t border-zinc-200 px-6 py-4 text-sm text-rose-700 dark:border-white/10 dark:text-rose-300">
                    <span class="font-semibold">Alasan reject:</span> {{ $record->rejection_reason }}
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
