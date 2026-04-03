<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'outbound_confirmed_at')) {
                $table->timestamp('outbound_confirmed_at')->nullable()->after('material_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'outbound_confirmed_at')) {
                $table->dropColumn('outbound_confirmed_at');
            }
        });
    }
};
