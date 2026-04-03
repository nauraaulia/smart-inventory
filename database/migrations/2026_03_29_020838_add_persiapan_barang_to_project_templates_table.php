<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_templates', function (Blueprint $table) {
            $table->integer('persiapan_barang')->nullable()->after('kategori');
        });
    }

    public function down(): void
    {
        Schema::table('project_templates', function (Blueprint $table) {
            $table->dropColumn('persiapan_barang');
        });
    }
};