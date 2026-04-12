<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid gap-3 md:grid-cols-5">
                <div>
                    <label class="mb-1 block text-xs font-medium">Dari Tanggal</label>
                    <input type="date" wire:model="dateFrom" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Sampai Tanggal</label>
                    <input type="date" wire:model="dateTo" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Module</label>
                    <select wire:model="module" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">Semua Module</option>
                        @foreach ($this->moduleOptions() as $module)
                            <option value="{{ $module }}">{{ $module }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Action</label>
                    <select wire:model="actionFilter" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">Semua Action</option>
                        @foreach ($this->actionOptions() as $action)
                            <option value="{{ $action }}">{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium">Role User</label>
                    <select wire:model="userRole" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">Semua Role</option>
                        @foreach ($this->roleOptions() as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <x-filament::button color="primary" wire:click="applyFilters">Apply Filter</x-filament::button>
            </div>
        </div>

        @if ($this->canViewFinanceSection())
            @php $finance = $this->financeStats(); @endphp
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                <h3 class="text-sm font-semibold">Laporan Finance</h3>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Total ACC Finance</p>
                        <p class="text-2xl font-semibold">{{ number_format($finance['acc_count']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Total Pengadaan (PO)</p>
                        <p class="text-2xl font-semibold">{{ number_format($finance['pengadaan_count']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">PO Selesai</p>
                        <p class="text-2xl font-semibold">{{ number_format($finance['po_selesai_count']) }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if ($this->canViewOperasionalSection())
            @php $ops = $this->operasionalStats(); @endphp
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                <h3 class="text-sm font-semibold">Laporan Manager Operasional</h3>
                <div class="mt-3 grid gap-3 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Total ACC Manager Ops</p>
                        <p class="text-2xl font-semibold">{{ number_format($ops['acc_count']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">PO ke Supplier</p>
                        <p class="text-2xl font-semibold">{{ number_format($ops['po_ke_supplier']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Aging Siap Diproses</p>
                        <p class="text-2xl font-semibold">{{ number_format($ops['aging_siap_proses']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Aging Selesai</p>
                        <p class="text-2xl font-semibold">{{ number_format($ops['aging_selesai']) }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if ($this->canViewTeknikSection())
            @php $teknik = $this->teknikStats(); @endphp
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                <h3 class="text-sm font-semibold">Laporan Manager Teknik</h3>
                <div class="mt-3 grid gap-3 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Total Proyek</p>
                        <p class="text-2xl font-semibold">{{ number_format($teknik['project_total']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Proyek Menunggu</p>
                        <p class="text-2xl font-semibold">{{ number_format($teknik['project_waiting']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Proyek Selesai</p>
                        <p class="text-2xl font-semibold">{{ number_format($teknik['project_completed']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Rata-rata Tunggu (hari)</p>
                        <p class="text-2xl font-semibold">{{ number_format((float) $teknik['avg_wait_days'], 1, '.', '') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if ($this->canViewGudangSection())
            @php $gudang = $this->gudangStats(); @endphp
            <div class="rounded-xl border border-violet-200 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950/30">
                <h3 class="text-sm font-semibold">Laporan Gudang</h3>
                <div class="mt-3 grid gap-3 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Inbound Tercatat</p>
                        <p class="text-2xl font-semibold">{{ number_format($gudang['inbound_count']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Outbound Diambil</p>
                        <p class="text-2xl font-semibold">{{ number_format($gudang['outbound_taken_count']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Task Pending</p>
                        <p class="text-2xl font-semibold">{{ number_format($gudang['task_pending_count']) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-3 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">Task Done</p>
                        <p class="text-2xl font-semibold">{{ number_format($gudang['task_done_count']) }}</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <h3 class="mb-3 text-sm font-semibold">Matriks Akses Role</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-2 py-2 text-left">Role</th>
                            <th class="px-2 py-2 text-left">Akses Modul</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->roleAccessRows() as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-2 font-medium">{{ $row['role'] }}</td>
                                <td class="px-2 py-2">{{ $row['modules'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <h3 class="mb-3 text-sm font-semibold">Laporan Akses per Aktor</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-2 py-2 text-left">Nama</th>
                            <th class="px-2 py-2 text-left">Email</th>
                            <th class="px-2 py-2 text-left">Role</th>
                            <th class="px-2 py-2 text-left">Akses Modul</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->actorAccessRows() as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-2 font-medium">{{ $row['name'] }}</td>
                                <td class="px-2 py-2">{{ $row['email'] }}</td>
                                <td class="px-2 py-2">{{ $row['role'] }}</td>
                                <td class="px-2 py-2">{{ $row['modules'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <h3 class="mb-3 text-sm font-semibold">Detail Aktivitas (Filtered)</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-2 py-2 text-left">Waktu</th>
                            <th class="px-2 py-2 text-left">User</th>
                            <th class="px-2 py-2 text-left">Role</th>
                            <th class="px-2 py-2 text-left">Module</th>
                            <th class="px-2 py-2 text-left">Action</th>
                            <th class="px-2 py-2 text-left">Description</th>
                            <th class="px-2 py-2 text-left">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-2">{{ $row['created_at'] }}</td>
                                <td class="px-2 py-2">{{ $row['user_name'] }}</td>
                                <td class="px-2 py-2">{{ $row['user_role'] }}</td>
                                <td class="px-2 py-2">{{ $row['module'] }}</td>
                                <td class="px-2 py-2">{{ $row['action'] }}</td>
                                <td class="px-2 py-2">{{ $row['description'] }}</td>
                                <td class="px-2 py-2">{{ $row['ip_address'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-2 py-6 text-center text-gray-500">Tidak ada data untuk filter yang dipilih.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
