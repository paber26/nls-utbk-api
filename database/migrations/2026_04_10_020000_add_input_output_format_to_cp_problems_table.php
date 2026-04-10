<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('cp_problems', function (Blueprint $table) {
            $table->text('input_format_html')->nullable()->after('description_html');
            $table->text('output_format_html')->nullable()->after('input_format_html');
        });
    }

    public function down()
    {
        Schema::table('cp_problems', function (Blueprint $table) {
            $table->dropColumn(['input_format_html', 'output_format_html']);
        });
    }
};
