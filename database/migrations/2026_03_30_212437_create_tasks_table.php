<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->string('for_role');
            $table->string('type');

            $table->string('title');
            $table->text('message');

            $table->json('payload')->nullable();

            $table->enum('status', ['pending', 'done', 'rejected'])
                  ->default('pending');

            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('related_type')->nullable();

            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();

            $table->foreign('handled_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};