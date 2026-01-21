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
        Schema::table('application_performa', function (Blueprint $table) {
            $table->unsignedBigInteger('performa_sheet')->nullable()->after('user_id');
        });
    }
    public function down(): void
    {
        Schema::table('application_performa', function (Blueprint $table) {
            $table->dropColumn('performa_sheet');
        });
    }
};
