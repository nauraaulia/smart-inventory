<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * FUNGSI: Bikin table untuk nyimpen POTONGAN FISIK material
     * Misal: Kabel 100m dipotong jadi 3 bagian (50m, 30m, 20m)
     * Setiap potongan disimpen sebagai 1 row di table ini
     */
    public function up(): void
    {
        Schema::create('material_pieces', function (Blueprint $table) {
            $table->id();
            
            // RELASI KE MATERIAL
            $table->foreignId('material_id')
                ->constrained('materials')
                ->cascadeOnDelete();
            
            // RELASI KE PROJECT (kalau sudah di-book)
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();
            
            // LOKASI PENYIMPANAN
            $table->foreignId('shelf_location_id')
                ->nullable()
                ->constrained('shelf_locations')
                ->nullOnDelete();
            
            // SUPPLIER (kalau ada tracking)
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();
            
            // JUMLAH FISIK PIECE INI
            // Misal: piece #1 = 50 meter
            $table->decimal('quantity', 10, 2);
            
            // HARGA SATUAN (optional)
            $table->decimal('unit_price', 15, 2)->nullable();
            
            // STATUS PIECE
            // available = bisa dipakai
            // booked = sudah di-book untuk project tertentu
            // used = sudah terpakai
            // damaged = rusak
            $table->enum('status', ['available', 'booked', 'used', 'damaged'])
                ->default('available');
            
            // TIMESTAMP
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('used_at')->nullable();
            
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // INDEX untuk performa query
            $table->index(['material_id', 'status']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_pieces');
    }
};