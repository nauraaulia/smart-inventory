<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_pieces', function (Blueprint $table) {
            if (Schema::hasColumn('material_pieces', 'piece_code')) {
                $table->dropUnique(['piece_code']);
                $table->index('piece_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('material_pieces', function (Blueprint $table) {
            if (Schema::hasColumn('material_pieces', 'piece_code')) {
                $table->dropIndex(['piece_code']);
                $table->unique('piece_code');
            }
        });
    }
};
