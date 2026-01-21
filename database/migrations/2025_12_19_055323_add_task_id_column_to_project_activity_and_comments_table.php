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
        Schema::table('project_activity_and_comments', function (Blueprint $table) {
             $table->unsignedBigInteger('task_id')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_activity_and_comments', function (Blueprint $table) {
            $table->dropColumn('task_id');
        });
    }
};
