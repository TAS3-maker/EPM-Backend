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
        Schema::table('leavespolicy', function (Blueprint $table) {
            $table->string('halfday_period')->nullable()->after('leave_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leavespolicy', function (Blueprint $table) {
            $table->dropColumn('halfday_period');
        });
    }
};
