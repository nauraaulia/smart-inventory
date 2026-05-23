<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('outbound_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_items', 'selected_piece_ids')) {
                $table->json('selected_piece_ids')->nullable()->after('unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_items', function (Blueprint $table): void {
            if (Schema::hasColumn('outbound_items', 'selected_piece_ids')) {
                $table->dropColumn('selected_piece_ids');
            }
        });
    }
};
