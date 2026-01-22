<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
            $table->unsignedBigInteger('project_estimation_by')
                ->nullable()
                ->after('sales_person_id');

            $table->unsignedBigInteger('project_call_by')
                ->nullable()
                ->after('project_estimation_by');
        });
    }

    public function down(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
            $table->dropColumn([
                'project_estimation_by',
                'project_call_by'
            ]);
        });
    }
};
