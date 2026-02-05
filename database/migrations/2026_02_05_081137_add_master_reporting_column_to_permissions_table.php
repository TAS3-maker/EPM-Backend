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
            $table->enum('master_reporting', $enumValues)->default('0')->nullable()->after('sheet_reporting');
        });
    }

    public function down(): void
    {
         Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('master_reporting');
        });
    }
};
