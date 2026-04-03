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
    Schema::create('project_template_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('project_template_id')->constrained()->onDelete('cascade');
        $table->foreignId('material_id')->constrained();
        $table->decimal('jumlah_kebutuhan', 10, 2);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_template_items');
    }
};
