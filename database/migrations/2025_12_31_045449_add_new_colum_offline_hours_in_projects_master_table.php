<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
   
    public function up(): void
    {
        Schema::table('projects_master', function (Blueprint $table) {
            $enumValues = ['0', '1'];
            $table->enum('offline_hours', $enumValues)->nullable()->after('project_used_budget');

        });
    }

    public function down(): void
    {
        Schema::table('projects_master', function (Blueprint $table) {
             $table->dropColumn('offline_hours');
        });
    }
};
