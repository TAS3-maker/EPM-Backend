<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects_master', function (Blueprint $table) {
            $enumValues = ['0', '1'];
            $table->enum('project_tracking', $enumValues)->after('project_name');
            $table->text('project_status')->nullable()->after('project_tracking');
            $table->text('project_description')->nullable()->after('project_status');
            $table->text('project_budget')->nullable()->after('project_description');
            $table->text('project_hours')->nullable()->after('project_budget');
            $table->integer('project_tag_activity')->nullable()->after('project_hours');
            $table->text('project_used_hours')->nullable()->after('project_tag_activity');
            $table->text('project_used_budget')->nullable()->after('project_used_hours');
        });
    }

    public function down(): void
    {
        Schema::table('projects_master', function (Blueprint $table) {
            $table->dropColumn([
                'project_tracking',
                'project_status',
                'project_description',
                'project_budget',
                'project_hours',
                'project_tag_activity',
                'project_used_hours',
                'project_used_budget',
            ]);
        });
    }
};
