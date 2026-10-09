<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Rows hold ids and values, never names or emails.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id');
            $table->enum('channel', ['rest', 'mcp']);
            $table->string('action', 64);
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->json('changes');
            $table->dateTime('created_at', 6);

            $table->index(['group_id', 'created_at'], 'idx_audit_group_time');
            $table->index(['actor_id', 'created_at'], 'idx_audit_actor_time');
            $table->foreign('actor_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
