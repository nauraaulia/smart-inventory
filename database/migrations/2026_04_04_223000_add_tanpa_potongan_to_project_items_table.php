<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_items', function (Blueprint $table) {
            if (!Schema::hasColumn('project_items', 'tanpa_potongan')) {
                $table->boolean('tanpa_potongan')->default(false)->after('jumlah_diminta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_items', function (Blueprint $table) {
            if (Schema::hasColumn('project_items', 'tanpa_potongan')) {
                $table->dropColumn('tanpa_potongan');
            }
        });
    }
};
