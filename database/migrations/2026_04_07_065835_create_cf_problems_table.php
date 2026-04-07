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
        Schema::create('cf_problems', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('mapel_id')->constrained('mapel')->onDelete('cascade');
            $table->integer('cf_contest_id');
            $table->string('cf_index');
            $table->string('name');
            $table->json('tags')->nullable();
            $table->integer('rating')->nullable();
            $table->integer('points')->default(100);
            $table->timestamps();

            $table->unique(['cf_contest_id', 'cf_index', 'mapel_id'], 'cf_unique_problem_mapel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cf_problems');
    }
};
