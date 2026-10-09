<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Rows exist only when the assignment's scope is "learners".
     */
    public function up(): void
    {
        Schema::create('assignment_learners', function (Blueprint $table) {
            $table->foreignId('assignment_id');
            $table->foreignId('user_id');
            $table->timestamp('created_at')->nullable();

            $table->primary(['assignment_id', 'user_id']);
            $table->index('user_id', 'idx_assignment_learners_user');
            $table->foreign('assignment_id')->references('id')->on('assignments');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_learners');
    }
};
