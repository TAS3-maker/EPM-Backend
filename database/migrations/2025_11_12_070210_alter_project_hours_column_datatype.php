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
            $table->decimal('total_hours', 10, 2)->nullable()->change();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('used_hours', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('total_hours')->change();
        });
            
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('used_hours')->change();
        });
    }
};
