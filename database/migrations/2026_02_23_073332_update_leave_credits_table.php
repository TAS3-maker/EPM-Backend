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

            $table->enum('employment_status', [
                'provisional',
                'appointed',
                'notice'
            ])->default('provisional')->after('user_id');

            $table->date('cycle_start_date')->nullable()->after('employment_status');
            $table->date('cycle_end_date')->nullable()->after('cycle_start_date');

            $table->decimal('carry_forward_balance', 5, 2)->default(0)->after('cycle_end_date');

            $table->decimal('total_used', 5, 2)->default(0)->after('carry_forward_balance');

            $table->decimal('provisional_leave_taken', 5, 2)->default(0)->after('total_used');
            $table->integer('provisional_extended_months')->default(0)->after('provisional_leave_taken');

            $table->date('notice_start_date')->nullable()->after('provisional_extended_months');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_credits', function (Blueprint $table) {

            $table->dropColumn([
                'employment_status',
                'cycle_start_date',
                'cycle_end_date',
                'carry_forward_balance',
                'total_used',
                'provisional_leave_taken',
                'provisional_extended_months',
                'notice_start_date'
            ]);
        });
    }
};
