<?php

namespace Database\Seeders;

use App\Models\ProjectTemplate;
use Illuminate\Database\Seeder;

class ProjectTemplateKodeSeeder extends Seeder
{
    public function run(): void
    {
        // Semua template dalam 1 kategori → kode_kategori SAMA
        $mapping = [
            // Infrastruktur Menara → TWR
            'Pemasangan Tower'         => 'TWR',
            'Maintenance Tower'        => 'TWR',
            'Emergency Perbaikan'      => 'TWR',

            // Sistem Komunikasi → COM
            'Setting & Pasang Radio'   => 'COM',
            'Instalasi Repeater'       => 'COM',
            'Jaringan WAN & LAN'       => 'COM',
            'Pemasangan Telepon Radio'  => 'COM',

            // Sistem Keamanan → SEC
            'Upgrade Air Protection'   => 'SEC',
            'Grounding System'         => 'SEC',
            'Instalasi CCTV'           => 'SEC',

            // Energi Alternatif → NRG
            'Pemasangan Solar Cell'    => 'NRG',
        ];

        foreach ($mapping as $namaTemplate => $kodeKategori) {
            ProjectTemplate::where('nama_template', $namaTemplate)
                ->update(['kode_kategori' => $kodeKategori]);
        }
    }
}