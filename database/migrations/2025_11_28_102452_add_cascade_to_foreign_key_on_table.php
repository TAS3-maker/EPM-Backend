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
         Schema::table('project_relations', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->change();
            $table->integer('client_id')->nullable()->change();
            $table->integer('communication_id')->nullable()->change();
            $table->integer('source_id')->nullable()->change();
            $table->integer('account_id')->nullable()->change();

            $table->foreign('project_id')
                  ->references('id')
                  ->on('projects_master')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
            $table->integer('client_id')->nullable(false)->change();
            $table->integer('communication_id')->nullable(false)->change();
            $table->integer('source_id')->nullable(false)->change();
            $table->integer('account_id')->nullable(false)->change();
            
            $table->dropForeign(['project_id']);
        });
    }
};
