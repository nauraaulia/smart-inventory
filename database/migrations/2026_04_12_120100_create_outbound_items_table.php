<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbound_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbound_id')->constrained('outbounds')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('qty', 12, 2);
            $table->string('unit', 50)->nullable();
            $table->timestamps();

            $table->index(['outbound_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_items');
    }
};
