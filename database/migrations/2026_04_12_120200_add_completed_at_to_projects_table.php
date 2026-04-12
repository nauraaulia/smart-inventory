<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            if (!Schema::hasColumn('projects', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('outbound_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            if (Schema::hasColumn('projects', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
