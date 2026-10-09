<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MySQL has no partial indexes, so removed_key carries the "one active
     * assignment per content item per group" rule: every active row holds 0,
     * and a removal sets removed_key to the row's own id. The schema builder
     * has no CHECK constraints, so the one that keeps removed_at and
     * removed_key in step is added with a statement of its own.
     */
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id');
            $table->foreignId('content_item_id');
            $table->dateTime('due_at');
            $table->enum('scope', ['group', 'learners'])->default('group');
            $table->foreignId('created_by');
            $table->dateTime('removed_at')->nullable();
            $table->unsignedBigInteger('removed_key')->default(0);
            $table->timestamps();

            $table->unique(['group_id', 'content_item_id', 'removed_key'], 'uq_assignments_active');
            $table->index(['group_id', 'removed_at', 'due_at'], 'idx_assignments_group');
            $table->foreign('group_id')->references('id')->on('learner_groups');
            $table->foreign('content_item_id')->references('id')->on('content_items');
            $table->foreign('created_by')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE assignments ADD CONSTRAINT chk_assignments_removed CHECK ((removed_at IS NULL) = (removed_key = 0))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
