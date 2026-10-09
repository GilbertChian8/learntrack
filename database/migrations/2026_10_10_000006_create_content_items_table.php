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
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('topic', 100);
            $table->enum('type', ['article', 'question_set']);
            $table->unsignedSmallInteger('estimated_minutes');
            $table->timestamps();

            $table->index('topic', 'idx_content_topic');
            $table->index('title', 'idx_content_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
