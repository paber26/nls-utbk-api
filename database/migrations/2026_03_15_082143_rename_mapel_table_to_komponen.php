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
        if (Schema::hasTable('mapel') && ! Schema::hasTable('komponen')) {
            Schema::rename('mapel', 'komponen');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('komponen') && ! Schema::hasTable('mapel')) {
            Schema::rename('komponen', 'mapel');
        }
    }
};
