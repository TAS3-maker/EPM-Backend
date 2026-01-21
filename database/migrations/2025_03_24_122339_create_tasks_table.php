<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable(); // ✅ Link to project
            $table->unsignedBigInteger('project_manager_id')->nullable(); // ✅ Assign project manager
            $table->string('title'); // ✅ Task title
            $table->text('description')->nullable(); // ✅ Task description
            $table->integer('hours')->nullable(); // ✅ Hours assigned to the task
            $table->date('deadline')->nullable(); // ✅ Task deadline
            $table->enum('status', ['To do', 'In Progress', 'Completed', 'Cancel'])->default('To do'); // ✅ Task status

            // Foreign Keys
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('project_manager_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tasks');
    }
};
