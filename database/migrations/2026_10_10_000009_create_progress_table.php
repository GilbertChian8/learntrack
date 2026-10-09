<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The schema builder has no CHECK constraints, so the
     * score check is added with a statement of its own.
     */
    public function up(): void
    {
        Schema::create('progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id');
            $table->foreignId('user_id');
            $table->enum('status', ['in_progress', 'completed']);
            $table->unsignedTinyInteger('score')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'user_id'], 'uq_progress_pair');
            $table->index('user_id', 'idx_progress_user');
            $table->foreign('assignment_id')->references('id')->on('assignments');
            $table->foreign('user_id')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE progress ADD CONSTRAINT chk_progress_score CHECK (score IS NULL OR score <= 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('progress');
    }
};
