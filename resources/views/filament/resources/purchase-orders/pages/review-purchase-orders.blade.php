<x-filament-panels::page>
    <div class="max-w-6xl mx-auto p-6 space-y-6">
        @if ($groups->isEmpty())
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 dark:bg-white/5 dark:border-white/10">
                <p class="text-sm text-gray-600 dark:text-gray-300">Tidak ada data PR approved yang valid untuk dibuatkan PO.</p>
                <div class="mt-4">
                    <x-filament::button tag="a" :href="\App\Filament\Resources\PurchaseRequests\PurchaseRequestResource::getUrl('index')">
                        Kembali ke Purchase Requests
                    </x-filament::button>
                </div>
            </div>
        @else
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Draft Surat Pembelian (Purchase Order)</h1>
                <p class="text-sm text-gray-500 dark:text-gray-300">Konversi kebutuhan Purchase Request (PR) ke satuan pesan supplier.</p>
            </div>

            @if ($errors->any())
                <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-red-950/30 dark:text-red-300">
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('po.publish') }}" class="space-y-6">
                @csrf

                @foreach ($groups as $groupIndex => $group)
                    @php
                        $draftNumber = sprintf('PO-%s-%02d-%03d', now()->format('Y'), now()->format('m'), $groupIndex + 1);
                    @endphp

                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 dark:bg-white/5 dark:border-white/10">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4 border-b border-gray-200 pb-2 dark:border-white/10">Informasi Surat</h2>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Pilih Supplier</label>
                                <input
                                    type="text"
                                    value="{{ $group['supplier_name'] }}"
                                    readonly
                                    class="w-full rounded-md border border-gray-300 bg-gray-100 text-gray-600 shadow-sm px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10 dark:text-gray-200"
                                >
                                <input type="hidden" name="groups[{{ $groupIndex }}][supplier_id]" value="{{ $group['supplier_id'] }}">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Tanggal Terbit</label>
                                <input
                                    type="date"
                                    name="groups[{{ $groupIndex }}][po_date]"
                                    value="{{ $group['po_date'] }}"
                                    required
                                    class="w-full rounded-md border border-emerald-300 bg-emerald-50 text-gray-900 shadow-sm px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-white"
                                >
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Nomor Surat</label>
                                <input
                                    type="text"
                                    value="{{ $draftNumber }}"
                                    readonly
                                    class="w-full rounded-md border border-gray-300 bg-gray-100 text-gray-700 font-semibold shadow-sm px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10 dark:text-gray-100"
                                >
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden dark:bg-white/5 dark:border-white/10">
                        <div class="p-6 border-b border-gray-200 dark:border-white/10">
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Rincian Konversi Barang</h2>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                                <thead class="bg-gray-50 dark:bg-white/5">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Nama Barang</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Kebutuhan Sistem</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32 dark:text-gray-300">Qty Pesan <span class="text-red-500">*</span></th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48 dark:text-gray-300">Satuan Pesan <span class="text-red-500">*</span></th>
                                    </tr>
                                </thead>

                                <tbody class="bg-white divide-y divide-gray-200 dark:bg-transparent dark:divide-white/10">
                                    @foreach ($group['items'] as $itemIndex => $item)
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium dark:text-white">
                                                {{ $item['material_name'] }}
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-300">{{ implode(', ', $item['project_codes']) }}</div>

                                                <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][material_id]" value="{{ $item['material_id'] }}">
                                                <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][qty_system]" value="{{ $item['qty_system'] }}">
                                                <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][unit_system]" value="{{ $item['unit_system'] }}">
                                                @foreach ($item['pr_ids'] as $prId)
                                                    <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][pr_ids][]" value="{{ $prId }}">
                                                @endforeach
                                                @foreach ($item['project_ids'] as $projectId)
                                                    <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][project_ids][]" value="{{ $projectId }}">
                                                @endforeach
                                                @foreach ($item['project_codes'] as $projectCode)
                                                    <input type="hidden" name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][project_codes][]" value="{{ $projectCode }}">
                                                @endforeach
                                            </td>

                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                                                    {{ number_format((float) $item['qty_system'], 1, '.', '') }} {{ $item['unit_system'] }}
                                                </span>
                                            </td>

                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][qty_order]"
                                                    value="{{ $item['qty_order_default'] }}"
                                                    required
                                                    class="w-full rounded-md border border-emerald-300 bg-emerald-50 text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm px-3 py-2 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-white"
                                                >
                                            </td>

                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <input
                                                    type="text"
                                                    name="groups[{{ $groupIndex }}][items][{{ $itemIndex }}][unit_order]"
                                                    value="{{ $item['unit_order_default'] }}"
                                                    required
                                                    class="w-full rounded-md border border-emerald-300 bg-emerald-50 text-gray-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm px-3 py-2 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-white"
                                                >
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach

                <div class="mt-6 flex items-center justify-end space-x-4">
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\PurchaseRequests\PurchaseRequestResource::getUrl('index')">
                        Batal
                    </x-filament::button>

                    <x-filament::button type="submit" color="primary" icon="heroicon-o-paper-airplane">
                        Simpan &amp; Terbitkan PO
                    </x-filament::button>
                </div>
            </form>
        @endif
    </div>
</x-filament-panels::page>
