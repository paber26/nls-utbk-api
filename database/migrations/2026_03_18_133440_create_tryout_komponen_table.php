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
        Schema::create('tryout_komponen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tryout_id')->constrained('tryout')->cascadeOnDelete();
            $table->foreignId('komponen_id')->constrained('komponen')->cascadeOnDelete();
            $table->integer('urutan')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tryout_komponen');
    }
};
