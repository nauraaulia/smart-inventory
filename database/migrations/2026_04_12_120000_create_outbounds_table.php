<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->enum('status', ['pending', 'ready', 'taken'])->default('pending');
            $table->boolean('outbound_manual')->default(false);
            $table->text('alasan_manual')->nullable();
            $table->string('foto_bukti')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'project_id']);
        });

        // Backfill outbound existing dari project yang sudah punya booking / history outbound.
        $projects = DB::table('projects')
            ->select('projects.id', 'projects.outbound_confirmed_at')
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('material_pieces')
                    ->whereColumn('material_pieces.booked_for_project_id', 'projects.id')
                    ->whereIn('material_pieces.status', ['booked', 'used']);
            })
            ->get();

        foreach ($projects as $project) {
            DB::table('outbounds')->insert([
                'project_id' => $project->id,
                'status' => $project->outbound_confirmed_at ? 'ready' : 'pending',
                'outbound_manual' => false,
                'ready_at' => $project->outbound_confirmed_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbounds');
    }
};
