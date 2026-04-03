<?php

namespace Database\Seeders;

use App\Models\Inbound;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\ProjectTemplate;
use App\Models\ShelfLocation;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TransactionSampleSeeder extends Seeder
{
    public function run(): void
    {
        $supplier1 = Supplier::where('kode_supplier', 'SUP-001')->first();
        $supplier2 = Supplier::where('kode_supplier', 'SUP-002')->first();

        $lokasiA01 = ShelfLocation::where('kode_lokasi', 'A-01')->first();
        $lokasiRet = ShelfLocation::where('kode_lokasi', 'RET-01')->first();

        $mKabel = Material::where('kode_material', 'MAT-KBL-001')->first();
        $mRadio = Material::where('kode_material', 'MAT-RAD-001')->first();
        $mBat = Material::where('kode_material', 'MAT-BAT-001')->first();

        if (!$supplier1 || !$lokasiA01 || !$mKabel || !$mRadio || !$mBat) {
            return;
        }

        // Inbound sample (event model akan update stok + create material_pieces)
        Inbound::query()->firstOrCreate(
            [
                'material_id' => $mKabel->id,
                'entry_date' => now()->subDays(9)->toDateString(),
                'jenis_inbound' => 'Baru',
            ],
            [
                'quantity' => 500,
                'supplier_id' => $supplier1->id,
                'shelf_location_id' => $lokasiA01->id,
                'satuan' => 'meter',
                'penerima' => 'Staff Gudang',
                'kondisi' => 'Bagus',
                'keterangan' => 'Stok awal kabel fiber',
            ]
        );

        Inbound::query()->firstOrCreate(
            [
                'material_id' => $mRadio->id,
                'entry_date' => now()->subDays(7)->toDateString(),
                'jenis_inbound' => 'Baru',
            ],
            [
                'quantity' => 10,
                'supplier_id' => $supplier1->id,
                'shelf_location_id' => $lokasiA01->id,
                'satuan' => 'unit',
                'penerima' => 'Staff Gudang',
                'kondisi' => 'Bagus',
                'keterangan' => 'Stok awal radio',
            ]
        );

        Inbound::query()->firstOrCreate(
            [
                'material_id' => $mBat->id,
                'entry_date' => now()->subDays(5)->toDateString(),
                'jenis_inbound' => 'Retur',
            ],
            [
                'quantity' => 2,
                'supplier_id' => $supplier2?->id,
                'shelf_location_id' => $lokasiRet?->id,
                'satuan' => 'unit',
                'penerima' => 'Staff Gudang',
                'kondisi' => 'Cacat Ringan',
                'keterangan' => 'Retur proyek: casing lecet',
            ]
        );

        $template = ProjectTemplate::where('kode_template', 'TPL-COM-001')->first();

        if ($template) {
            $project = Project::withoutEvents(function () use ($template) {
                return Project::query()->updateOrCreate(
                    ['kode_proyek' => 'PRJ-COM-' . now()->format('ym') . '-0001'],
                    [
                        'nama_proyek' => 'Upgrade Repeater Area Timur',
                        'project_template_id' => $template->id,
                        'tanggal_mulai' => now()->subDays(2)->toDateString(),
                        'tanggal_selesai' => now()->addDays(20)->toDateString(),
                        'h_minus_persiapan' => 5,
                        'buffer_stock' => 15,
                        'catatan_teknis' => 'Prioritas tinggi untuk area blank spot.',
                        'lokasi' => 'Site Area Timur',
                        'status' => 'Processing',
                        'material_confirmed_at' => now()->subDay(),
                    ]
                );
            });

            $mapItems = [
                ['material' => $mKabel, 'jumlah_diminta' => 150],
                ['material' => $mRadio, 'jumlah_diminta' => 2],
            ];

            foreach ($mapItems as $item) {
                if (!$item['material']) {
                    continue;
                }

                ProjectItem::query()->updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'material_id' => $item['material']->id,
                    ],
                    [
                        'jumlah_diminta' => $item['jumlah_diminta'],
                        'jumlah_keluar' => 0,
                    ]
                );
            }

            $staffGudang = User::where('role', 'Staff Administrasi Gudang')->first();
            $managerOps = User::where('role', 'Manager Operasional')->first();
            $finance = User::where('role', 'Finance')->first();

            if ($staffGudang && $managerOps && $finance) {
                DB::table('purchase_requests')->updateOrInsert(
                    [
                        'material_id' => $mKabel->id,
                        'project_id' => $project->id,
                        'requested_by' => $staffGudang->id,
                        'status' => 'pending_manager',
                    ],
                    [
                        'approved_by_manager' => null,
                        'approved_by_finance' => null,
                        'quantity' => 120,
                        'reason' => 'Kebutuhan material proyek bertambah.',
                        'priority' => 'high',
                        'needed_date' => now()->addDays(10)->toDateString(),
                        'submitted_at' => now()->subHours(4),
                        'approved_by_manager_at' => null,
                        'approved_by_finance_at' => null,
                        'rejected_at' => null,
                        'rejection_reason' => null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        $tasks = [
            [
                'for_role' => 'Manager Operasional',
                'type' => 'approval_pr',
                'title' => 'Approval Purchase Request',
                'message' => 'Silakan review PR material kabel untuk proyek Area Timur.',
                'status' => 'pending',
            ],
            [
                'for_role' => 'Finance',
                'type' => 'approval_budget',
                'title' => 'Verifikasi Budget Material',
                'message' => 'Menunggu persetujuan anggaran material kabel.',
                'status' => 'pending',
            ],
            [
                'for_role' => 'Staff Administrasi Gudang',
                'type' => 'stok_check',
                'title' => 'Cek Ketersediaan Material',
                'message' => 'Lakukan pengecekan stok fisik mingguan.',
                'status' => 'done',
                'handled_at' => now()->subHours(1),
            ],
        ];

        foreach ($tasks as $task) {
            Task::query()->updateOrCreate(
                [
                    'for_role' => $task['for_role'],
                    'type' => $task['type'],
                    'title' => $task['title'],
                ],
                [
                    'message' => $task['message'],
                    'payload' => [
                        'source' => 'seeder',
                        'generated_at' => now()->toIso8601String(),
                    ],
                    'status' => $task['status'],
                    'related_id' => null,
                    'related_type' => null,
                    'handled_by' => null,
                    'handled_at' => $task['handled_at'] ?? null,
                ]
            );
        }
    }
}
