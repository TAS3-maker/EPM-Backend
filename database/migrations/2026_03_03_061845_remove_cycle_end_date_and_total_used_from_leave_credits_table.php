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
        Schema::table('leave_credits', function (Blueprint $table) {
            $table->dropColumn([
                'cycle_end_date',
                'total_used'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_credits', function (Blueprint $table) {
            $table->date('cycle_end_date')->nullable()->after('cycle_start_date');
            $table->decimal('total_used', 5, 2)->default(0)->after('carry_forward_balance');
        });
    }
};
