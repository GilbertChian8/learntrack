<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. "groups" is a reserved word in MySQL 8.
     */
    public function up(): void
    {
        Schema::create('learner_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id');
            $table->string('name');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learner_groups');
    }
};
