<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * FUNGSI: Tambah field untuk tracking status material di project
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Tambah timestamp kapan material dikonfirmasi
            $table->timestamp('material_confirmed_at')->nullable()->after('tanggal_selesai');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('material_confirmed_at');
        });
    }
};