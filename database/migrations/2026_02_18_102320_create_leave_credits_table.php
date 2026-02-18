<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**Run the migrations*/
    public function up(): void
    {
        if (!Schema::hasTable('leave_credits')) {

            Schema::create('leave_credits', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->integer('paid_leaves')->default(0);
                $table->integer('bunch_time');
                $table->integer('provisional_days');
                $table->date('joining_date');
                $table->timestamps();
            });
        }
    }

    /*Reverse the migrations*/
    public function down(): void
    {
        Schema::dropIfExists('leave_credits');
    }
};
