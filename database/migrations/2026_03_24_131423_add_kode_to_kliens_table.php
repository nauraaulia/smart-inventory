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
        Schema::table('kliens', function (Blueprint $table) {
            if (!Schema::hasColumn('kliens', 'kode_klien')) {
                $table->string('kode_klien')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('kliens', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kliens', function (Blueprint $table) {
            //
        });
    }
};
