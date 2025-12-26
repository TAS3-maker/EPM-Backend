<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leavespolicy', function (Blueprint $table) {
            $enumValues = ['0', '1'];
            $table->enum('is_wfh', $enumValues)->default('0')->nullable()->after('approved_bymanager');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leavespolicy', function (Blueprint $table) {
             $table->dropColumn('is_wfh');
        });
    }
};
