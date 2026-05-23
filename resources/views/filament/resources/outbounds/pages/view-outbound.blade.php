<x-filament-panels::page>
    <style>
        .outbound-panel {
            background-color: rgb(255 255 255);
            border-color: rgb(228 228 231);
        }

        .outbound-layout {
            max-width: 78rem;
            gap: 1.75rem;
        }

        .outbound-spacious-card {
            padding: 1.25rem 1rem;
        }

        @media (min-width: 768px) {
            .outbound-spacious-card {
                padding: 1.5rem 1.25rem;
            }
        }

        .dark .outbound-panel {
            background-color: rgb(24 24 27) !important;
            border-color: rgb(255 255 255 / 0.12) !important;
        }

        .outbound-soft-card {
            background-color: rgb(244 244 245);
            border-color: rgb(228 228 231);
        }

        .dark .outbound-soft-card {
            background-color: rgb(39 39 42) !important;
            border-color: rgb(255 255 255 / 0.12) !important;
        }

        .dark .outbound-table-head {
            background-color: rgb(39 39 42) !important;
            color: rgb(212 212 216) !important;
        }

        .dark .outbound-table-row td {
            color: rgb(228 228 231) !important;
            border-color: rgb(255 255 255 / 0.1) !important;
        }

        .outbound-potongan-list {
            margin: 0;
            padding-left: 1rem;
            display: grid;
            gap: 0.25rem;
        }

        .outbound-badge-potongan {
            border: 1px solid rgb(253 224 71);
            background-color: rgb(254 249 195);
            color: rgb(133 77 14);
        }

        .dark .outbound-badge-potongan {
            border-color: rgb(251 191 36 / 0.45);
            background-color: rgb(245 158 11 / 0.15);
            color: rgb(253 230 138);
        }

        .outbound-badge-utuh {
            border: 1px solid rgb(134 239 172);
            background-color: rgb(220 252 231);
            color: rgb(22 101 52);
        }

        .dark .outbound-badge-utuh {
            border-color: rgb(52 211 153 / 0.45);
            background-color: rgb(16 185 129 / 0.15);
            color: rgb(167 243 208);
        }

        .outbound-badge-mapped {
            border: 1px solid rgb(125 211 252);
            background-color: rgb(224 242 254);
            color: rgb(3 105 161);
        }

        .dark .outbound-badge-mapped {
            border-color: rgb(56 189 248 / 0.45);
            background-color: rgb(14 165 233 / 0.15);
            color: rgb(186 230 253);
        }

        .outbound-badge-kurang {
            border: 1px solid rgb(252 165 165);
            background-color: rgb(254 242 242);
            color: rgb(153 27 27);
        }

        .dark .outbound-badge-kurang {
            border-color: rgb(248 113 113 / 0.45);
            background-color: rgb(239 68 68 / 0.15);
            color: rgb(254 202 202);
        }

        .outbound-badge-lengkap {
            border: 1px solid rgb(134 239 172);
            background-color: rgb(220 252 231);
            color: rgb(22 101 52);
        }

        .dark .outbound-badge-lengkap {
            border-color: rgb(52 211 153 / 0.45);
            background-color: rgb(16 185 129 / 0.15);
            color: rgb(167 243 208);
        }
    </style>

    @php
        $outbound = $this->record;

        $statusLabel = match ($outbound->status) {
            'pending' => 'Menunggu',
            'ready' => 'Siap Diambil',
            'taken' => 'Sudah Diambil',
            default => ucfirst((string) $outbound->status),
        };

        $statusBadge = match ($outbound->status) {
            'pending' => 'bg-amber-100 text-amber-800 border-amber-200',
            'ready' => 'bg-sky-100 text-sky-800 border-sky-200',
            'taken' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
            default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
        };

        $items = $this->getDisplayItems();
        $shortageItems = collect($items)
            ->where('fulfillment_status', 'Kurang')
            ->values();
    @endphp

    <div class="outbound-layout mx-auto w-full space-y-6 px-2 sm:px-4 lg:px-6">
        <section class="outbound-panel rounded-2xl border p-6 shadow-sm">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="outbound-soft-card outbound-spacious-card rounded-xl border">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Kode Proyek</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $outbound->project?->kode_proyek ?: '-' }}</div>
                </div>

                <div class="outbound-soft-card outbound-spacious-card rounded-xl border">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Nama Proyek</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $outbound->project?->nama_proyek ?: '-' }}</div>
                </div>

                <div class="outbound-soft-card outbound-spacious-card rounded-xl border">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Status Outbound</div>
                    <div class="mt-2">
                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $statusBadge }} dark:border-white/20 dark:bg-white/10 dark:text-white">
                            {{ $statusLabel }}
                        </span>
                    </div>
                </div>

                <div class="outbound-soft-card outbound-spacious-card rounded-xl border">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Tanggal Akses</div>
                    <div class="mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $currentDate }}</div>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-white/10">
                    <div class="text-zinc-500 dark:text-zinc-300">Outbound dibuat</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ optional($outbound->created_at)->format('d M Y H:i') ?: '-' }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-white/10">
                    <div class="text-zinc-500 dark:text-zinc-300">Siap diambil</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ optional($outbound->ready_at)->format('d M Y H:i') ?: '-' }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-white/10">
                    <div class="text-zinc-500 dark:text-zinc-300">Sudah diambil</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ optional($outbound->taken_at)->format('d M Y H:i') ?: '-' }}</div>
                </div>
            </div>
        </section>

        @if ($outbound->status === 'ready')
            <section class="rounded-2xl border border-sky-200 bg-sky-50 p-4 shadow-sm dark:border-sky-300/30 dark:bg-sky-400/10">
                <div class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-200">Status Siap Diambil</div>
                <div class="mt-2 text-sm leading-relaxed text-sky-900 dark:text-sky-100">
                    Manager Teknik dapat menekan tombol <strong>Konfirmasi Pengambilan</strong> di header halaman ini untuk upload foto bukti dan mengubah status menjadi diambil.
                </div>
            </section>
        @endif

        @if ($outbound->outbound_manual && filled($outbound->alasan_manual))
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-300/30 dark:bg-amber-400/10">
                <div class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-200">Alasan Outbound Manual</div>
                <div class="mt-2 text-sm leading-relaxed text-amber-900 dark:text-amber-100">{{ $outbound->alasan_manual }}</div>
            </section>
        @endif

        @if ($shortageItems->isNotEmpty())
            <section class="rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm dark:border-rose-300/30 dark:bg-rose-500/10">
                <div class="text-sm font-semibold text-rose-700 dark:text-rose-200">Masih ada barang yang kurang:</div>
                <div class="mt-3 overflow-x-auto rounded-lg border border-rose-200/80 dark:border-rose-200/25">
                    <table class="min-w-full text-xs">
                        <thead class="bg-rose-100/80 text-left uppercase tracking-wide text-rose-700 dark:bg-rose-500/15 dark:text-rose-200">
                            <tr>
                                <th class="px-3 py-2 font-semibold">Nama Barang</th>
                                <th class="px-3 py-2 font-semibold">Sisa Kurang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rose-200/70 dark:divide-rose-200/20">
                            @foreach ($shortageItems as $shortage)
                                <tr>
                                    <td class="px-3 py-2 text-rose-900 dark:text-rose-100">{{ $shortage['material'] }}</td>
                                    <td class="px-3 py-2 font-semibold text-rose-700 dark:text-rose-200">{{ $shortage['shortage_qty'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="outbound-panel rounded-2xl border shadow-sm">
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
                        <thead class="outbound-table-head bg-zinc-100 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                            <tr>
                                <th class="px-6 py-3">Nama Material</th>
                                <th class="px-6 py-3">Qty Total</th>
                                <th class="px-6 py-3">Lokasi Rak</th>
                                <th class="px-6 py-3">Rincian Potongan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach ($items as $item)
                                <tr class="outbound-table-row">
                                    <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">{{ $item['material'] }}</td>
                                    <td class="px-6 py-4 text-zinc-700 dark:text-zinc-200">{{ $item['qty'] }}</td>
                                    <td class="px-6 py-4 text-zinc-700 dark:text-zinc-200">{{ $item['racks'] }}</td>
                                    <td class="px-6 py-4 text-zinc-700 dark:text-zinc-200">
                                        @if (!empty($item['ai_pick_instructions']))
                                            <div class="mb-2 inline-flex items-center rounded-full border border-sky-300 bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:border-sky-400/40 dark:bg-sky-500/20 dark:text-sky-200">
                                                Instruksi AI
                                            </div>
                                            <ul class="outbound-potongan-list text-xs mb-2">
                                                @foreach ($item['ai_pick_instructions'] as $instruction)
                                                    <li>{{ $instruction }}</li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        @if (!empty($item['piece_details']))
                                            <div class="mb-2 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $item['is_potongan'] ? 'outbound-badge-potongan' : 'outbound-badge-mapped' }}">
                                                {{ $item['piece_badge'] ?? ($item['is_potongan'] ? 'Potongan' : 'Pieces Dipilih') }}
                                            </div>
                                            <ul class="outbound-potongan-list text-xs">
                                                @foreach ($item['piece_details'] as $detail)
                                                    <li>{{ $detail }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold outbound-badge-utuh">
                                                Utuh
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($outbound->status === 'taken' && filled($outbound->foto_bukti))
            <section class="outbound-panel rounded-2xl border p-6 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Bukti Pengambilan</div>
                <div class="mt-3">
                    <img src="{{ asset('storage/' . $outbound->foto_bukti) }}" alt="Bukti outbound" class="max-h-[420px] w-auto rounded-xl border border-zinc-200 object-contain dark:border-white/10" />
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
