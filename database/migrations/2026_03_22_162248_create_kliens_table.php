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
    Schema::create('kliens', function (Blueprint $table) {
        $table->id();
        $table->string('kode_klien')->unique();
        $table->string('nama_klien');
        $table->string('no_telp')->nullable();
        $table->text('alamat')->nullable();
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kliens');
    }
};
