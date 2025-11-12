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
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('used_budgets',10,2)->default(0.00)->after('budget');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('used_hours')->nullable()->after('total_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('used_budgets');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('used_hours');
        });
    }
};
