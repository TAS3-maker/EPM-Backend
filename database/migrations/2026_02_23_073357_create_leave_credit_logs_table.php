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
        Schema::create('leave_credit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');

            $table->integer('year');
            $table->integer('month');

            $table->integer('worked_days')->default(0);

            $table->decimal('monthly_paid_leave', 5, 2)->default(0);
            $table->decimal('used_in_month', 5, 2)->default(0);

            $table->boolean('converted_to_unpaid')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'year', 'month']);
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_credit_logs');
    }
};
