<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $enumValues = ['0', '1', '2', '3'];
            $table->enum('sheet_reporting', $enumValues)->default('0')->nullable()->after('standup_sheet');
        });
    }

    public function down(): void
    {
         Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('sheet_reporting');
        });
    }
};
