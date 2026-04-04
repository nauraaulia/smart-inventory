<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            if (!Schema::hasColumn('inbounds', 'is_opening_balance')) {
                $table->boolean('is_opening_balance')->default(false)->after('jenis_inbound');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            if (Schema::hasColumn('inbounds', 'is_opening_balance')) {
                $table->dropColumn('is_opening_balance');
            }
        });
    }
};
