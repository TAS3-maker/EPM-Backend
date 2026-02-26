<?php

use Carbon\Carbon;
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
        Schema::table('leave_credits', function (Blueprint $table) {
            $table->unsignedTinyInteger('month')
                ->default(Carbon::now()->month)
                ->after('user_id');
            $table->unsignedSmallInteger('year')
                ->default(Carbon::now()->year)
                ->after('month');
            $table->integer('notice_period_days')->default(45)->after('notice_start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_credits', function (Blueprint $table) {
            $table->dropColumn(['month', 'year','notice_period_days']);
        });
    }
};
