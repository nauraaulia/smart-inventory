<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_items', function (Blueprint $table) {
            $table->id();
            
            // Header inbound
            $table->foreignId('inbound_id')
                  ->constrained('inbounds')
                  ->onDelete('cascade');
            
            // Material apa
            $table->foreignId('material_id')
                  ->constrained('materials')
                  ->onDelete('cascade');
            
            // Qty yang masuk
            $table->decimal('quantity', 10, 2);
            
            // Apakah langsung potongan atau utuh?
            $table->boolean('is_potongan')->default(false);
            
            // Kalau potongan, panjang berapa
            $table->decimal('panjang_per_piece', 10, 2)->nullable();
            
            // Jumlah pieces (kalau langsung beberapa potongan)
            $table->integer('jumlah_pieces')->default(1);
            
            // Lokasi akan disimpan dimana
            $table->foreignId('shelf_location_id')
                  ->nullable()
                  ->constrained('shelf_locations')
                  ->nullOnDelete();
            
            $table->text('keterangan')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_items');
    }
};