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
        Schema::create('group_learners', function (Blueprint $table) {
            $table->foreignId('group_id');
            $table->foreignId('user_id');
            $table->timestamp('created_at')->nullable();

            $table->primary(['group_id', 'user_id']);
            $table->index('user_id', 'idx_group_learners_user');
            $table->foreign('group_id')->references('id')->on('learner_groups');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_learners');
    }
};
