<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('inbounds', function (Blueprint $table) {
        // Cek dulu, kalau kolomnya belum ada, baru dibuat
        if (!Schema::hasColumn('inbounds', 'supplier_id')) {
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
        }
        if (!Schema::hasColumn('inbounds', 'shelf_location_id')) {
            $table->foreignId('shelf_location_id')->nullable()->constrained('shelf_locations');
        }
        // Tambahin satuan & penerima kalau belum ada
        if (!Schema::hasColumn('inbounds', 'satuan')) $table->string('satuan')->nullable();
        if (!Schema::hasColumn('inbounds', 'penerima')) $table->string('penerima')->nullable();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            //
        });
    }
};
