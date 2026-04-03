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
    Schema::create('inbounds', function (Blueprint $table) {
        $table->id();
        // Relasi ke tabel material (pastiin nama tabelnya 'materials' ya)
        $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
        $table->integer('quantity'); // Jumlah masuk
        $table->date('entry_date'); // Tanggal masuk
        $table->string('supplier')->nullable(); // Nama supplier (opsional)
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbounds');
    }
};
