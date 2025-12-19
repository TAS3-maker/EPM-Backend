<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('project_relations', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_person_id')
                  ->nullable()
                  ->change();
        });
    }

    public function down()
    {
        Schema::table('project_relations', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_person_id')
                  ->nullable(false)
                  ->change();
        });
    }
};
