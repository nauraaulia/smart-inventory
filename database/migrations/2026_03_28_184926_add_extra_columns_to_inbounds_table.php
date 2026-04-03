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
        Schema::table('inbounds', function (Blueprint $table) {
            $table->foreignId('shelf_location_id')->nullable()->constrained('shelf_locations');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->string('satuan')->nullable();
            $table->string('penerima')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            //
        });
    }
};
