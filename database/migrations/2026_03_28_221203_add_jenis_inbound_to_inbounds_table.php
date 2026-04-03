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
            // Kita kasih default 'Baru' biar data lama kamu gak error
            $table->string('jenis_inbound')->default('Baru')->after('entry_date');
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropColumn('jenis_inbound');
        });
    }
};
