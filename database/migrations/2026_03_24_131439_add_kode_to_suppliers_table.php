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
        Schema::table('suppliers', function (Blueprint $table) {
            // Cek kalau belum ada kode_supplier, baru dibikin
            if (!Schema::hasColumn('suppliers', 'kode_supplier')) {
                $table->string('kode_supplier')->nullable()->unique()->after('id');
            }
            // Cek kalau belum ada kotak sampah, baru dibikin
            if (!Schema::hasColumn('suppliers', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            //
        });
    }
};
