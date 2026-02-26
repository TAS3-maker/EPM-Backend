<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_credit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('leave_credit_id')->nullable()->after('id');
        });

        // Copy user_id → leave_credit_id
        DB::statement("
            UPDATE leave_credit_logs l
            JOIN leave_credits c ON c.user_id = l.user_id
            SET l.leave_credit_id = c.id
        ");

        Schema::table('leave_credit_logs', function (Blueprint $table) {

            $table->unsignedBigInteger('leave_credit_id')->nullable(false)->change();

            $table->foreign('leave_credit_id')
                ->references('id')
                ->on('leave_credits')
                ->onDelete('cascade');

            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_credit_logs', function (Blueprint $table) {

            $table->unsignedBigInteger('user_id')->nullable();

            $table->dropForeign(['leave_credit_id']);
            $table->dropColumn('leave_credit_id');
        });
    }
};
