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
        Schema::create('permissions', function (Blueprint $table) {
             $table->id();

            // Not nullable user_id with foreign key
            $table->unsignedBigInteger('user_id');

            // Permission fields as ENUM(0,1,2,3)
            $enumValues = ['0','1','2','3'];

            $table->enum('dashboard', $enumValues)->nullable();
            $table->enum('employee_management', $enumValues)->nullable();
            $table->enum('roles', $enumValues)->nullable();
            $table->enum('department', $enumValues)->nullable();
            $table->enum('team', $enumValues)->nullable();
            $table->enum('clients', $enumValues)->nullable();
            $table->enum('projects', $enumValues)->nullable();
            $table->enum('assigned_projects_inside_projects_assigned', $enumValues)->nullable();
            $table->enum('unassigned_projects_inside_projects_assigned', $enumValues)->nullable();
            $table->enum('performance_sheets', $enumValues)->nullable();
            $table->enum('pending_sheets_inside_performance_sheets', $enumValues)->nullable();
            $table->enum('manage_sheets_inside_performance_sheets', $enumValues)->nullable();
            $table->enum('unfilled_sheets_inside_performance_sheets', $enumValues)->nullable();
            $table->enum('manage_leaves', $enumValues)->nullable();
            $table->enum('activity_tags', $enumValues)->nullable();
            $table->enum('leaves', $enumValues)->nullable();
            $table->enum('teams', $enumValues)->nullable();
            $table->enum('leave_management', $enumValues)->nullable();
            $table->enum('project_management', $enumValues)->nullable();
            $table->enum('assigned_projects_inside_project_management', $enumValues)->nullable();
            $table->enum('unassigned_projects_inside_project_management', $enumValues)->nullable();
            $table->enum('performance_sheet', $enumValues)->nullable();
            $table->enum('performance_history', $enumValues)->nullable();
            $table->enum('projects_assigned', $enumValues)->nullable();

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
        Schema::dropIfExists('permissions');
    }
};
