<x-filament-panels::page>
    <div class="space-y-5">
        @if (session('success'))
            <x-filament::section>
                <p class="text-sm text-green-700">{{ session('success') }}</p>
            </x-filament::section>
        @endif

        @if (session('error'))
            <x-filament::section>
                <p class="text-sm text-red-700">{{ session('error') }}</p>
            </x-filament::section>
        @endif

        @if ($errors->any())
            <x-filament::section>
                <ul class="list-disc space-y-1 pl-5 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section>
            <form method="GET" action="{{ \App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('receive') }}" class="flex flex-col gap-2 sm:flex-row">
                <input class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5" type="text" name="po_number" value="{{ $poNumber }}" placeholder="Contoh: PO/SI/IV/2026/0001">
                <x-filament::button type="submit">Cari PO</x-filament::button>
            </form>
        </x-filament::section>

        @if ($poNumber !== '' && !$purchaseOrder)
            <x-filament::section>
                <p class="text-sm text-gray-600">Nomor PO tidak ditemukan.</p>
            </x-filament::section>
        @endif

        @if ($purchaseOrder)
            @php
                $isFormLocked = $isInboundLocked || $purchaseOrder->status !== 'waiting_inbound';
            @endphp
            <x-filament::section>
                <x-slot name="heading">PO {{ $purchaseOrder->po_number }}</x-slot>

                <div class="mb-4 flex flex-wrap gap-2 text-xs">
                    <span class="inline-flex rounded-md bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">Supplier: {{ $purchaseOrder->supplier?->nama_supplier ?? '-' }}</span>
                    <span class="inline-flex rounded-md bg-primary-50 px-2 py-1 font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">Status: {{ $purchaseOrder->progress_status ?? $purchaseOrder->status }}</span>
                </div>

                <form method="POST" action="{{ route('po.receive', ['purchaseOrder' => $purchaseOrder->id]) }}" class="relative space-y-4" x-data="{ shelfLocationId: @js((string) old('shelf_location_id', $selectedShelfLocationId)), isSubmitting: false, isLocked: @js($isFormLocked) }" x-on:submit.capture="isSubmitting = true">
                    @csrf

                    <div x-show="isSubmitting" class="absolute inset-0 z-20 cursor-wait rounded-xl bg-white/30 dark:bg-black/20"></div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Nama Penerima</label>
                        <input x-bind:readonly="isSubmitting || isLocked" class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5" type="text" name="receiver_name" value="{{ old('receiver_name', $receiverName) }}">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Lokasi Rak</label>
                        <select x-model="shelfLocationId" x-bind:disabled="isLocked" name="shelf_location_id" required class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5">
                            <option value="">Pilih lokasi rak</option>
                            @foreach ($shelfLocationOptions as $id => $label)
                                <option value="{{ $id }}" @selected((string) old('shelf_location_id', $selectedShelfLocationId) === (string) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($isInboundLocked)
                        <p class="text-xs text-gray-500 dark:text-gray-400">Rak dan nama penerima sudah dikunci dari penerimaan inbound pertama untuk PO ini.</p>
                    @endif

                    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Material</th>
                                <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Pesanan PO</th>
                                <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Sudah Diterima</th>
                                <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Sisa</th>
                                <th class="border-b border-gray-200 p-3 text-left font-semibold dark:border-white/10">Qty Datang Sekarang</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($purchaseOrder->items as $item)
                                @php
                                    $ordered = (float) $item->qty_order;
                                    $received = (float) ($item->qty_received ?? 0);
                                    $remaining = max(0, $ordered - $received);
                                @endphp
                                <tr class="odd:bg-white even:bg-gray-50/60 dark:odd:bg-transparent dark:even:bg-white/5">
                                    <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ $item->material?->nama_material ?? '-' }}</td>
                                    <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ number_format($ordered, 1, '.', '') }} {{ $item->unit_order }}</td>
                                    <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">{{ number_format($received, 1, '.', '') }} {{ $item->unit_order }}</td>
                                    <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">
                                        <span class="inline-flex rounded-md bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 dark:bg-warning-900/30 dark:text-warning-300">
                                            {{ number_format($remaining, 1, '.', '') }} {{ $item->unit_order }}
                                        </span>
                                    </td>
                                    <td class="border-b border-gray-200 p-3 align-top dark:border-white/10">
                                        <input x-bind:readonly="isSubmitting" class="block w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-primary-500 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5" type="number" step="0.01" min="0" max="{{ $remaining }}" name="received_quantities[{{ $item->id }}]" value="{{ $remaining > 0 ? number_format($remaining, 2, '.', '') : '0' }}">
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <x-filament::button
                            tag="a"
                            color="gray"
                            :href="\App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('index')"
                        >
                            Close
                        </x-filament::button>

                        @if ($purchaseOrder->status === 'waiting_inbound')
                            <x-filament::button color="danger" type="submit" x-bind:disabled="isSubmitting || !shelfLocationId">
                                Terima Inbound
                            </x-filament::button>
                        @endif
                    </div>
                </form>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
