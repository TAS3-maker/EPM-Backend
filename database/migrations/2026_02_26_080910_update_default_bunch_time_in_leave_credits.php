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
            $table->integer('bunch_time')->default(12)->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_credits', function (Blueprint $table) {
            $table->integer('bunch_time')->default(4)->change(); // set your previous default
        });
    }
};
