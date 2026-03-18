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
        Schema::table('tryout', function (Blueprint $table) {
            $table->dropForeign('fk_tryout_mapel');
            $table->dropIndex('idx_mapel');
            $table->dropColumn('mapel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tryout', function (Blueprint $table) {
            $table->unsignedBigInteger('mapel_id')->nullable();
        });
    }
};
