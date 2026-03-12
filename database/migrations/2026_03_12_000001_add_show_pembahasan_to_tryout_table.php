<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tryout', function (Blueprint $table) {
            $table->boolean('show_pembahasan')
                ->default(false)
                ->after('pesan_selesai');
        });
    }

    public function down(): void
    {
        Schema::table('tryout', function (Blueprint $table) {
            $table->dropColumn('show_pembahasan');
        });
    }
};
