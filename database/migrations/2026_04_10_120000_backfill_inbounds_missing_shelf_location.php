<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('inbounds') || !Schema::hasTable('shelf_locations')) {
            return;
        }

        if (!Schema::hasColumn('inbounds', 'shelf_location_id')) {
            return;
        }

        $fallbackId = DB::table('shelf_locations')
            ->where('kode_lokasi', 'UNASSIGNED')
            ->value('id');

        if (!$fallbackId) {
            $fallbackId = DB::table('shelf_locations')->insertGetId([
                'kode_lokasi' => 'UNASSIGNED',
                'nama_lokasi' => 'Belum Ditentukan',
                'keterangan' => 'Lokasi fallback otomatis untuk inbound lama tanpa rak.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('inbounds')
            ->whereNull('shelf_location_id')
            ->update([
                'shelf_location_id' => $fallbackId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tidak mengembalikan ke NULL agar tidak menghapus perbaikan data historis.
    }
};
