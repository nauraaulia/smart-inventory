<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            if (!Schema::hasColumn('inbounds', 'purchase_order_id')) {
                $table->foreignId('purchase_order_id')->nullable()->after('project_id')->constrained('purchase_orders')->nullOnDelete();
            }

            if (!Schema::hasColumn('inbounds', 'purchase_order_item_id')) {
                $table->foreignId('purchase_order_item_id')->nullable()->after('purchase_order_id')->constrained('purchase_order_items')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            if (Schema::hasColumn('inbounds', 'purchase_order_item_id')) {
                $table->dropConstrainedForeignId('purchase_order_item_id');
            }

            if (Schema::hasColumn('inbounds', 'purchase_order_id')) {
                $table->dropConstrainedForeignId('purchase_order_id');
            }
        });
    }
};
