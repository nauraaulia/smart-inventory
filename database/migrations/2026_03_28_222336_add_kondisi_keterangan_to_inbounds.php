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
            // Tambah kondisi dan keterangan
            $table->string('kondisi')->nullable()->after('jenis_inbound');
            $table->text('keterangan')->nullable()->after('kondisi');
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
