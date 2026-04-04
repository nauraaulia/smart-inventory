<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'project_allocated_at')) {
                $table->timestamp('project_allocated_at')->nullable()->after('submitted_at');
            }

            if (!Schema::hasColumn('purchase_requests', 'project_allocated_qty')) {
                $table->decimal('project_allocated_qty', 10, 2)->default(0)->after('project_allocated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_requests', 'project_allocated_qty')) {
                $table->dropColumn('project_allocated_qty');
            }

            if (Schema::hasColumn('purchase_requests', 'project_allocated_at')) {
                $table->dropColumn('project_allocated_at');
            }
        });
    }
};
