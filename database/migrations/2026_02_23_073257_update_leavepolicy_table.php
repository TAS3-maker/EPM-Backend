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
            // New columns
            $table->decimal('total_hours', 8, 2)->nullable()->after('hours');
            $table->decimal('deducted_days', 8, 2)->default(0)->after('total_hours');
            $table->decimal('sandwich_extra_days', 8, 2)->default(0)->after('deducted_days');
            $table->enum('employment_period', [
                'provisional',
                'appointed',
                'notice'
            ])->nullable()->after('leave_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leavespolicy', function (Blueprint $table) {
            $table->dropColumn([
                'total_hours',
                'deducted_days',
                'sandwich_extra_days',
                'employment_period'
            ]);
        });
    }
};
