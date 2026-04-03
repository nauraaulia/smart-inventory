<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_templates', function (Blueprint $table) {
            $table->string('kode_kategori', 10)->after('id')->nullable();
            // TWR, COM, SEC, NRG — cuma prefix, bukan kode unik
        });
    }

    public function down(): void
    {
        Schema::table('project_templates', function (Blueprint $table) {
            $table->dropColumn('kode_kategori');
        });
    }
};