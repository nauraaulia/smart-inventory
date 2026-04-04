<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kliens')) {
            Schema::drop('kliens');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('kliens')) {
            Schema::create('kliens', function (Blueprint $table) {
                $table->id();
                $table->string('kode_klien')->unique();
                $table->string('nama_klien');
                $table->string('no_telp')->nullable();
                $table->text('alamat')->nullable();
                $table->timestamps();
            });
        }
    }
};
