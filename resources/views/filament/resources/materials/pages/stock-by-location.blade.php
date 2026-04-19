<x-filament-panels::page>
    <div class="space-y-5">

        {{-- FILTER --}}
        <x-filament::section>
            <x-slot name="heading">Lokasi Stok</x-slot>
            <div class="grid gap-y-5 md:grid-cols-4 md:gap-x-4">
                <div class="space-y-1.5">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Cari Material / Rak</label>
                    <input
                        type="text"
                        wire:model="search"
                        placeholder="Contoh: kabel / RAK-A1"
                        autocomplete="off"
                        class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5"
                    >
                </div>

                <div class="space-y-1.5">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Filter Lokasi Rak</label>
                    <select wire:model="shelfLocationId" class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5">
                        <option value="">Semua lokasi</option>
                        @foreach ($shelfLocationOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Filter Material</label>
                    <select wire:model="materialId" class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5">
                        <option value="">Semua material</option>
                        @foreach ($materialOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-end gap-2 pt-7 md:pt-0">
                    <x-filament::button type="button" wire:click="applyFilter">
                        Terapkan
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="resetFilter"
                    >
                        Reset
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        {{-- TABEL --}}
        <x-filament::section>
            <x-slot name="heading">Daftar Stok Per Lokasi</x-slot>

            @if (empty($rows))
                <p class="text-sm text-gray-600 dark:text-gray-300">Belum ada stok available/booked yang terpetakan ke rak.</p>
            @else
                <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Lokasi Rak</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Kode Material</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Nama Material</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Piece ID</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Panjang Piece</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Status</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Available</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Booked</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Total</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr class="odd:bg-white even:bg-gray-50/60 dark:odd:bg-transparent dark:even:bg-white/5">
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ $row['shelf_label'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ $row['material_code'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ $row['material_name'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">#{{ $row['piece_id'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ number_format((float) $row['piece_length'], 2, '.', '') }} {{ $row['unit'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">
                                            <span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $row['piece_status'] === 'booked' ? 'bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-300' : 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-300' }}">
                                                {{ ucfirst($row['piece_status']) }}
                                            </span>
                                        </td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ number_format((float) $row['available_qty'], 2, '.', '') }} {{ $row['unit'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ number_format((float) $row['booked_qty'], 2, '.', '') }} {{ $row['unit'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ number_format((float) $row['total_qty'], 2, '.', '') }} {{ $row['unit'] }}</td>
                                        <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">
                                            {!! '<a href="' . $this->getMoveUrl($row['piece_id']) . '" class="inline-flex items-center gap-1 rounded-md bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100 dark:bg-primary-900/30 dark:text-primary-300 dark:hover:bg-primary-900/50">🔀 Pindah Rak</a>' !!}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </x-filament::section>

    </div>

    {{-- MODAL PINDAH RAK --}}
    @if ($showMoveModal)
        <div
            id="move-modal-overlay"
            style="position:fixed;top:0;left:0;right:0;bottom:0;width:100vw;height:100vh;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);"
        >
            <div style="width:100%;max-width:440px;margin:0 16px;border-radius:16px;box-shadow:0 25px 50px rgba(0,0,0,0.3);padding:28px;" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10">

                <h3 style="margin:0 0 20px;font-size:17px;font-weight:600;" class="text-gray-900 dark:text-white">
                    🔀 Pindah Rak — Piece #{{ $movingPieceId }}
                </h3>

                <div style="border-radius:8px;padding:12px 16px;margin-bottom:20px;" class="bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10">
                    <div style="font-size:11px;margin-bottom:4px;" class="text-gray-500 dark:text-gray-400">Dari Rak:</div>
                    <div style="font-weight:600;" class="text-gray-900 dark:text-white">{{ $movingFromShelfLabel }}</div>
                </div>

                <form method="GET" action="{{ \App\Filament\Resources\Materials\MaterialResource::getUrl('stock-by-location') }}">
                    <input type="hidden" name="move_piece_id" value="{{ $movingPieceId }}">
                    <input type="hidden" name="search" value="{{ $search }}">
                    <input type="hidden" name="shelf_location_id" value="{{ $shelfLocationId }}">
                    <input type="hidden" name="material_id" value="{{ $materialId }}">
                    <input type="hidden" name="do_move" value="1">

                    <label style="display:block;font-size:14px;font-weight:500;margin-bottom:8px;" class="text-gray-700 dark:text-gray-200">
                        Pilih Rak Tujuan
                    </label>
                    <select
                        name="target_shelf_location_id"
                        style="width:100%;padding:8px 12px;border-radius:8px;font-size:14px;margin-bottom:24px;"
                        class="border border-gray-300 dark:border-white/15 bg-white dark:bg-white/5 dark:text-white"
                    >
                        <option value="">-- Pilih rak tujuan --</option>
                        @foreach ($shelfLocationOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <div style="display:flex;justify-content:flex-end;gap:12px;">
                        {!! '<a href="' . $this->getCancelUrl() . '" style="display:inline-flex;align-items:center;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:500;text-decoration:none;border:1px solid #d1d5db;" class="text-gray-700 dark:text-gray-300 dark:border-white/15 hover:bg-gray-50 dark:hover:bg-white/5">Batal</a>' !!}
                        <button
                            type="submit"
                            style="display:inline-flex;align-items:center;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:500;border:none;cursor:pointer;background:#4f46e5;color:white;"
                        >
                            Pindahkan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            (function() {
                var modal = document.getElementById('move-modal-overlay');
                if (modal) {
                    document.body.appendChild(modal);
                    document.body.style.overflow = 'hidden';
                }
            })();
        </script>
    @endif

</x-filament-panels::page>