<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'draft_approved_by')) {
                $table->foreignId('draft_approved_by')
                    ->nullable()
                    ->after('requested_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('purchase_requests', 'draft_approved_at')) {
                $table->timestamp('draft_approved_at')
                    ->nullable()
                    ->after('draft_approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_requests', 'draft_approved_by')) {
                $table->dropConstrainedForeignId('draft_approved_by');
            }

            if (Schema::hasColumn('purchase_requests', 'draft_approved_at')) {
                $table->dropColumn('draft_approved_at');
            }
        });
    }
};
