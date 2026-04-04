<?php

namespace Database\Seeders;

use App\Models\Material;
use App\Models\ProjectTemplate;
use App\Models\ProjectTemplateItem;
use App\Models\ShelfLocation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin',
                'email' => 'admin@admin.com',
                'id_custom' => 'JAU-001',
                'role' => 'Administrator IT',
            ],
            [
                'name' => 'Manager Operasional',
                'email' => 'manager.ops@smartinventory.test',
                'id_custom' => 'JAU-002',
                'role' => 'Manager Operasional',
            ],
            [
                'name' => 'Finance',
                'email' => 'finance@smartinventory.test',
                'id_custom' => 'JAU-003',
                'role' => 'Finance',
            ],
            [
                'name' => 'Manager Teknik',
                'email' => 'manager.teknik@smartinventory.test',
                'id_custom' => 'JAU-004',
                'role' => 'Manager Teknik',
            ],
            [
                'name' => 'Staff Gudang',
                'email' => 'staff.gudang@smartinventory.test',
                'id_custom' => 'JAU-005',
                'role' => 'Staff Administrasi Gudang',
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'id_custom' => $user['id_custom'],
                    'role' => $user['role'],
                    'password' => Hash::make('123456'),
                    'email_verified_at' => now(),
                ]
            );
        }

        $suppliers = [
            [
                'kode_supplier' => 'SUP-001',
                'nama_supplier' => 'PT Kabel Nusantara',
                'alamat' => 'Jakarta',
                'no_telp' => '0811111111',
                'email' => 'sales@kabelnusantara.test',
                'lead_time_hari' => 5,
                'catatan' => 'Supplier kabel utama',
            ],
            [
                'kode_supplier' => 'SUP-002',
                'nama_supplier' => 'PT Solusi Energi',
                'alamat' => 'Bandung',
                'no_telp' => '0822222222',
                'email' => 'cs@solusienergi.test',
                'lead_time_hari' => 7,
                'catatan' => 'Panel dan baterai',
            ],
            [
                'kode_supplier' => 'SUP-003',
                'nama_supplier' => 'PT Infrastruktur Menara',
                'alamat' => 'Surabaya',
                'no_telp' => '0833333333',
                'email' => 'order@inframenara.test',
                'lead_time_hari' => 10,
                'catatan' => 'Kebutuhan tower & support',
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::updateOrCreate(
                ['kode_supplier' => $supplier['kode_supplier']],
                $supplier
            );
        }

        $locations = [
            ['kode_lokasi' => 'A-01', 'nama_lokasi' => 'Rak A-01', 'keterangan' => 'Kabel dan konektor'],
            ['kode_lokasi' => 'A-02', 'nama_lokasi' => 'Rak A-02', 'keterangan' => 'Perangkat radio'],
            ['kode_lokasi' => 'B-01', 'nama_lokasi' => 'Rak B-01', 'keterangan' => 'Panel & baterai'],
            ['kode_lokasi' => 'RET-01', 'nama_lokasi' => 'Area Retur', 'keterangan' => 'Barang retur/rusak'],
        ];

        foreach ($locations as $location) {
            ShelfLocation::updateOrCreate(
                ['kode_lokasi' => $location['kode_lokasi']],
                $location
            );
        }

        $materials = [
            [
                'kode_material' => 'MAT-KBL-001',
                'material_code' => 'MC-KBL-001',
                'nama_material' => 'Kabel Fiber Optic 100m',
                'kategori' => 'Komunikasi',
                'deskripsi' => 'Kabel backbone',
                'satuan' => 'meter',
                'default_roll_length' => 300,
                'min_stok' => 200,
                'stok' => 0,
                'booked' => 0,
                'stok_rusak' => 0,
            ],
            [
                'kode_material' => 'MAT-RAD-001',
                'material_code' => 'MC-RAD-001',
                'nama_material' => 'Radio Link Outdoor',
                'kategori' => 'Komunikasi',
                'deskripsi' => 'Perangkat radio point-to-point',
                'satuan' => 'unit',
                'default_roll_length' => null,
                'min_stok' => 5,
                'stok' => 0,
                'booked' => 0,
                'stok_rusak' => 0,
            ],
            [
                'kode_material' => 'MAT-BAT-001',
                'material_code' => 'MC-BAT-001',
                'nama_material' => 'Battery Backup 100Ah',
                'kategori' => 'Energi',
                'deskripsi' => 'Backup power untuk site',
                'satuan' => 'unit',
                'default_roll_length' => null,
                'min_stok' => 4,
                'stok' => 0,
                'booked' => 0,
                'stok_rusak' => 0,
            ],
            [
                'kode_material' => 'MAT-CCTV-001',
                'material_code' => 'MC-SEC-001',
                'nama_material' => 'Kamera CCTV Outdoor',
                'kategori' => 'Keamanan',
                'deskripsi' => 'CCTV outdoor IP66',
                'satuan' => 'unit',
                'default_roll_length' => null,
                'min_stok' => 6,
                'stok' => 0,
                'booked' => 0,
                'stok_rusak' => 0,
            ],
        ];

        foreach ($materials as $material) {
            Material::updateOrCreate(
                ['kode_material' => $material['kode_material']],
                $material
            );
        }

        $templates = [
            [
                'kode_template' => 'TPL-TWR-001',
                'kode_kategori' => 'TWR',
                'nama_template' => 'Pemasangan Tower',
                'kategori' => 'Infrastruktur Menara',
                'persiapan_barang' => 14,
                'estimasi_durasi' => 30,
                'estimasi_persiapan' => 7,
                'buffer_stock' => 10,
                'catatan_teknis' => 'Paket standar menara dan perangkat pendukung.',
                'is_active' => true,
            ],
            [
                'kode_template' => 'TPL-COM-001',
                'kode_kategori' => 'COM',
                'nama_template' => 'Instalasi Repeater',
                'kategori' => 'Sistem Komunikasi',
                'persiapan_barang' => 10,
                'estimasi_durasi' => 20,
                'estimasi_persiapan' => 5,
                'buffer_stock' => 15,
                'catatan_teknis' => 'Butuh sinkronisasi frekuensi dan pengujian sinyal.',
                'is_active' => true,
            ],
        ];

        foreach ($templates as $templateData) {
            $template = ProjectTemplate::updateOrCreate(
                ['kode_template' => $templateData['kode_template']],
                $templateData
            );

            $templateItems = match ($templateData['kode_template']) {
                'TPL-TWR-001' => [
                    ['kode_material' => 'MAT-KBL-001', 'jumlah_kebutuhan' => 300],
                    ['kode_material' => 'MAT-BAT-001', 'jumlah_kebutuhan' => 2],
                ],
                default => [
                    ['kode_material' => 'MAT-RAD-001', 'jumlah_kebutuhan' => 2],
                    ['kode_material' => 'MAT-KBL-001', 'jumlah_kebutuhan' => 150],
                    ['kode_material' => 'MAT-CCTV-001', 'jumlah_kebutuhan' => 2],
                ],
            };

            foreach ($templateItems as $item) {
                $material = Material::where('kode_material', $item['kode_material'])->first();

                if (!$material) {
                    continue;
                }

                ProjectTemplateItem::updateOrCreate(
                    [
                        'project_template_id' => $template->id,
                        'material_id' => $material->id,
                    ],
                    [
                        'jumlah_kebutuhan' => $item['jumlah_kebutuhan'],
                    ]
                );
            }
        }
    }
}
