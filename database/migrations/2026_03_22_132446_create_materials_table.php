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
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('kode_material')->unique();
            $table->string('nama_material');
            $table->text('deskripsi')->nullable();
            $table->integer('stok')->default(0);
            $table->string('satuan'); // misal: pcs, kg, box
            $table->timestamps();
            $table->integer('min_stok')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
