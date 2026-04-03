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
    Schema::create('project_templates', function (Blueprint $table) {
        $table->id();
        $table->string('kode_template')->unique();
        $table->string('nama_template');
        $table->string('kategori')->nullable();
        $table->integer('estimasi_durasi')->nullable(); // dalam hari
        $table->integer('estimasi_persiapan')->nullable(); // hari sebelum mulai
        $table->integer('buffer_stock')->default(0); // dalam persen
        $table->text('catatan_teknis')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_templates');
    }
};
