<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->decimal('qty_system', 12, 2);
            $table->string('unit_system', 50)->default('Meter');
            $table->decimal('qty_order', 12, 2);
            $table->string('unit_order', 50);
            $table->json('pr_ids')->nullable();
            $table->json('project_ids')->nullable();
            $table->json('project_codes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
