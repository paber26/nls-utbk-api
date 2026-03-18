<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename columns to match requirement: kode, mata_uji, nama_komponen
        // Use raw SQL to avoid doctrine/dbal dependency.
        DB::statement(
            "ALTER TABLE `komponen` CHANGE `nama` `nama_komponen` varchar(255) NOT NULL, CHANGE `tingkat` `mata_uji` varchar(255) NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE `komponen` CHANGE `nama_komponen` `nama` varchar(255) NOT NULL, CHANGE `mata_uji` `tingkat` varchar(255) NOT NULL"
        );
    }
};
