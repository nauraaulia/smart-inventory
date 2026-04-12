<x-filament-panels::page>
    <div class="space-y-5">
        <x-filament::section>
            <x-slot name="heading">Lokasi Stok</x-slot>

            <form method="GET" action="{{ \App\Filament\Resources\Materials\MaterialResource::getUrl('stock-by-location') }}" class="grid gap-y-5 md:grid-cols-4 md:gap-x-4" autocomplete="off">
                <div class="space-y-1.5">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Cari Material / Rak</label>
                    <input
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Contoh: geomembrane / RAK-A1"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5"
                    >
                </div>

                <div class="space-y-1.5">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Filter Lokasi Rak</label>
                    <select
                        name="shelf_location_id"
                        class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5"
                    >
                        <option value="">Semua lokasi</option>
                        @foreach ($shelfLocationOptions as $id => $label)
                            <option value="{{ $id }}" @selected((string) $shelfLocationId === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Filter Material</label>
                    <select
                        name="material_id"
                        class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5"
                    >
                        <option value="">Semua material</option>
                        @foreach ($materialOptions as $id => $label)
                            <option value="{{ $id }}" @selected((string) $materialId === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-end gap-2 pt-7 md:pt-0">
                    <x-filament::button type="submit">Terapkan</x-filament::button>
                    <x-filament::button
                        tag="a"
                        color="gray"
                        :href="\App\Filament\Resources\Materials\MaterialResource::getUrl('stock-by-location')"
                    >
                        Reset
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

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
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Status Piece</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Available</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Booked</th>
                                    <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Total</th>
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
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
