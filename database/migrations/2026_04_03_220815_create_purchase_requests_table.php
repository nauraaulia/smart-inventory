<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * FUNGSI: Bikin table untuk Purchase Request (PR)
     * PR ini auto-generated kalau stok material kurang
     * Flow: Draft → Approved by Manager → Approved by Finance → Order → Received
     */
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            
            // RELASI
            $table->foreignId('material_id')
                ->constrained('materials')
                ->cascadeOnDelete();
            
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();
            
            // YANG REQUEST (biasanya Staff Adm Gudang atau Sistem)
            $table->foreignId('requested_by')
                ->constrained('users')
                ->cascadeOnDelete();
            
            // YANG APPROVE (Manager Operasional)
            $table->foreignId('approved_by_manager')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            
            // YANG APPROVE (Finance)
            $table->foreignId('approved_by_finance')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            
            // DATA PR
            $table->decimal('quantity', 10, 2);
            $table->text('reason')->nullable();
            
            // STATUS PR
            // draft = baru dibuat, belum disubmit
            // pending_manager = menunggu approval manager
            // pending_finance = sudah disetujui manager, menunggu finance
            // approved = sudah disetujui semua
            // rejected = ditolak
            // ordered = sudah di-order ke supplier
            // received = barang sudah diterima
            $table->enum('status', [
                'draft',
                'pending_manager', 
                'pending_finance',
                'approved',
                'rejected',
                'ordered',
                'received'
            ])->default('draft');
            
            // PRIORITAS
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])
                ->default('medium');
            
            // TANGGAL DIBUTUHKAN
            $table->date('needed_date')->nullable();
            
            // TIMESTAMP APPROVAL
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_by_manager_at')->nullable();
            $table->timestamp('approved_by_finance_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            
            // ALASAN REJECT (kalau ditolak)
            $table->text('rejection_reason')->nullable();
            
            $table->timestamps();
            
            // INDEX
            $table->index(['material_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};