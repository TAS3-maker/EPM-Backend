<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   
    public function up(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
              $table->json('assignees')->nullable()->after('sales_person_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
           $table->dropColumn('assignees');
        });
    }
};
