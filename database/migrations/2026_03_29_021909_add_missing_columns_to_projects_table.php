<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('project_template_id')
                ->nullable()
                ->after('id')
                ->constrained('project_templates')
                ->nullOnDelete();

            $table->integer('h_minus_persiapan')
                ->nullable()
                ->after('tanggal_selesai');

            $table->integer('buffer_stock')
                ->default(0)
                ->after('h_minus_persiapan');

            $table->text('catatan_teknis')
                ->nullable()
                ->after('buffer_stock');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_template_id');
            $table->dropColumn([
                'h_minus_persiapan',
                'buffer_stock',
                'catatan_teknis',
            ]);
        });
    }
};