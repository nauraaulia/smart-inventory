<x-filament-panels::page>
    <style>
        .dark .outbound-summary-shell {
            background-color: rgb(24 24 27) !important;
            border-color: rgb(255 255 255 / 0.1) !important;
        }

        .dark .outbound-summary-card {
            background-color: rgb(39 39 42) !important;
            border-color: rgb(255 255 255 / 0.1) !important;
        }

        .dark .outbound-summary-label {
            color: rgb(203 213 225) !important;
        }

        .dark .outbound-summary-value {
            color: rgb(248 250 252) !important;
        }

        .outbound-detail-table tbody tr {
            background-color: transparent;
        }

        .outbound-detail-table tbody tr:hover {
            background-color: rgb(248 250 252);
        }

        .dark .outbound-detail-shell {
            background-color: rgb(24 24 27);
            border-color: rgb(255 255 255 / 0.1);
        }

        .dark .outbound-detail-head {
            background-color: rgb(39 39 42);
            color: rgb(203 213 225);
            border-color: rgb(255 255 255 / 0.1);
        }

        .dark .outbound-detail-table tbody tr {
            background-color: rgb(24 24 27);
        }

        .dark .outbound-detail-table tbody tr:hover {
            background-color: rgb(39 39 42);
        }

        .dark .outbound-detail-table tbody td {
            color: rgb(226 232 240);
            border-color: rgb(255 255 255 / 0.1);
        }

        .dark .outbound-detail-empty {
            color: rgb(203 213 225);
        }
    </style>

    <div class="mx-auto w-full max-w-6xl space-y-6">
        <section class="outbound-summary-shell rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="grid gap-6 md:grid-cols-2">
                <div class="outbound-summary-card rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800">
                    <div class="outbound-summary-label text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Nama Proyek</div>
                    <div class="outbound-summary-value mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $record->nama_proyek }}</div>
                </div>

                <div class="outbound-summary-card rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-zinc-800">
                    <div class="outbound-summary-label text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-300">Tanggal</div>
                    <div class="outbound-summary-value mt-2 text-base font-semibold text-zinc-900 dark:text-white">{{ $currentDate }}</div>
                </div>
            </div>
        </section>

        @if ($this->hasShortage())
            <section class="rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-sm dark:border-rose-400/30 dark:bg-rose-500/10">
                <div class="mb-3 text-sm font-semibold text-rose-700 dark:text-rose-200">
                    Barang yang belum terpenuhi dari stok booked
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-200">
                            <tr>
                                <th class="px-3 py-2">Nama Barang</th>
                                <th class="px-3 py-2">Dibutuhkan</th>
                                <th class="px-3 py-2">Sudah Booked</th>
                                <th class="px-3 py-2">Kurang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rose-200/70 dark:divide-rose-400/20">
                            @foreach ($shortageRows as $shortage)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-rose-900 dark:text-rose-100">{{ $shortage['material_name'] }}</td>
                                    <td class="px-3 py-2 text-rose-800 dark:text-rose-200">{{ $shortage['required'] }}</td>
                                    <td class="px-3 py-2 text-rose-800 dark:text-rose-200">{{ $shortage['booked'] }}</td>
                                    <td class="px-3 py-2 font-semibold text-rose-700 dark:text-rose-100">{{ $shortage['shortage'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="outbound-detail-shell rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="outbound-detail-head border-b border-zinc-200 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Daftar Barang Reservasi</h2>
            </div>

            @if (empty($rows))
                <div class="outbound-detail-empty px-6 py-10 text-sm text-zinc-600 dark:text-zinc-300">
                    Tidak ada barang dengan status booked untuk proyek ini.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="outbound-detail-table min-w-full text-sm">
                        <thead class="bg-zinc-100 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            <tr>
                                <th class="px-6 py-3">Lokasi Rak</th>
                                <th class="px-6 py-3">Nama Barang</th>
                                <th class="px-6 py-3">Kuantitas</th>
                                <th class="px-6 py-3">Tipe Barang</th>
                                <th class="px-6 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach ($rows as $row)
                                @php
                                    $isChecked = in_array($row['id'], array_map('intval', $pickedPieceIds), true);
                                    $typeBadgeClass = 'border-zinc-300 bg-zinc-100 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700/30 dark:text-zinc-200';
                                @endphp
                                <tr class="transition {{ $isChecked ? 'opacity-45' : 'opacity-100' }}">
                                    <td class="px-6 py-4 font-medium text-zinc-700 dark:text-zinc-200">{{ $row['rack'] }}</td>
                                    <td class="px-6 py-4 text-zinc-900 dark:text-white">{{ $row['material_name'] }}</td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center rounded-full border border-zinc-300 bg-zinc-100 px-3 py-1 text-xs font-bold text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700/30 dark:text-zinc-200">
                                            {{ $row['qty'] }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $typeBadgeClass }}">
                                            {{ $row['type'] }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <input
                                            type="checkbox"
                                            value="{{ $row['id'] }}"
                                            wire:model.live="pickedPieceIds"
                                            class="h-5 w-5 cursor-pointer rounded border-zinc-300 text-primary-600 focus:ring-primary-500 dark:border-zinc-500"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="flex items-center justify-end border-t border-zinc-200 px-6 py-4 dark:border-white/10">
                <x-filament::button
                    color="warning"
                    wire:click="confirmOutbound"
                    wire:loading.attr="disabled"
                    :disabled="! $this->canConfirmOutbound()"
                    icon="heroicon-o-truck"
                >
                    Konfirmasi &amp; Keluarkan Barang
                </x-filament::button>
            </div>
        </section>
    </div>
</x-filament-panels::page>
