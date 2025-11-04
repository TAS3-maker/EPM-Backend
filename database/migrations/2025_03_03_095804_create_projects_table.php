<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_name');
            $table->text('requirements')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->date('deadline')->nullable();
            $table->integer('total_hours')->default(0);
            $table->integer('total_working_hours')->default(0);
            $table->json('project_manager_id')->nullable();
            $table->unsignedBigInteger('tl_id')->nullable()->change();
            $table->longText('tags_activitys')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->longText('technology')->nullable();
            $table->timestamps();

            // Declare the foreign key columns first:
            $table->unsignedBigInteger('sales_team_id');
            $table->unsignedBigInteger('client_id');

            // Then add foreign keys:
            $table->foreign('sales_team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');

            // Virtual column and index:
            $table->string('project_manager_id_index')
                ->virtualAs("JSON_UNQUOTE(JSON_EXTRACT(project_manager_id, '$[0]'))");
            $table->index('project_manager_id_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};

