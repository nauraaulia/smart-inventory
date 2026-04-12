<x-filament-panels::page>
    @php
        $statusLabel = match ($record->status) {
            'pending' => 'Menunggu',
            'ready' => 'Siap Diambil',
            'taken' => 'Sudah Diambil',
            default => ucfirst((string) $record->status),
        };

        $statusBadge = match ($record->status) {
            'pending' => 'bg-amber-100 text-amber-800 border-amber-200',
            'ready' => 'bg-sky-100 text-sky-800 border-sky-200',
            'taken' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
            default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
        };

        $items = $this->getDisplayItems();
    @endphp

    <div class="mx-auto w-full max-w-6xl space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Kode Proyek</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->project?->kode_proyek ?: '-' }}</div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Nama Proyek</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->project?->nama_proyek ?: '-' }}</div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Status Outbound</div>
                    <div class="mt-2">
                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $statusBadge }} dark:border-white/20 dark:bg-white/10 dark:text-white">
                            {{ $statusLabel }}
                        </span>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Tanggal Akses</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $currentDate }}</div>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-white/10">
                    <div class="text-zinc-500 dark:text-zinc-300">Outbound dibuat</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ optional($record->created_at)->format('d M Y H:i') ?: '-' }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-white/10">
                    <div class="text-zinc-500 dark:text-zinc-300">Siap diambil</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ optional($record->ready_at)->format('d M Y H:i') ?: '-' }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-white/10">
                    <div class="text-zinc-500 dark:text-zinc-300">Sudah diambil</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ optional($record->taken_at)->format('d M Y H:i') ?: '-' }}</div>
                </div>
            </div>
        </section>

        @if ($record->outbound_manual && filled($record->alasan_manual))
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-300/30 dark:bg-amber-400/10">
                <div class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-200">Alasan Outbound Manual</div>
                <div class="mt-2 text-sm leading-relaxed text-amber-900 dark:text-amber-100">{{ $record->alasan_manual }}</div>
            </section>
        @endif

        <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Daftar Item Outbound</h2>
            </div>

            @if (empty($items))
                <div class="px-6 py-10 text-sm text-zinc-600 dark:text-zinc-300">
                    Item outbound belum tersedia.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-100 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            <tr>
                                <th class="px-6 py-3">Nama Material</th>
                                <th class="px-6 py-3">Qty</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach ($items as $item)
                                <tr>
                                    <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">{{ $item['material'] }}</td>
                                    <td class="px-6 py-4 text-zinc-700 dark:text-zinc-200">{{ $item['qty'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($record->status === 'taken' && filled($record->foto_bukti))
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Bukti Pengambilan</div>
                <div class="mt-3">
                    <img src="{{ asset('storage/' . $record->foto_bukti) }}" alt="Bukti outbound" class="max-h-[420px] w-auto rounded-xl border border-zinc-200 object-contain dark:border-white/10" />
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
