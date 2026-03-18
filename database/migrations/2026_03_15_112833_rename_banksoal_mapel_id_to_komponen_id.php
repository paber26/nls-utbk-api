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
        // Rename foreign key column from mapel_id to komponen_id
        // Using raw SQL to avoid requiring doctrine/dbal.
        Schema::getConnection()->getSchemaBuilder()->getConnection()->statement(
            "ALTER TABLE `banksoal` CHANGE `mapel_id` `komponen_id` BIGINT UNSIGNED NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::getConnection()->getSchemaBuilder()->getConnection()->statement(
            "ALTER TABLE `banksoal` CHANGE `komponen_id` `mapel_id` BIGINT UNSIGNED NOT NULL"
        );
    }
};
