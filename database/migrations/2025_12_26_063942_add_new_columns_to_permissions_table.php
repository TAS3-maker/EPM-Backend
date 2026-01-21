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
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                $enumValues = ['0', '1', '2', '3'];
                $table->enum('project_master', $enumValues)->default('0')->nullable()->after('projects_assigned');
                $table->enum('client_master', $enumValues)->default('0')->nullable()->after('project_master');
                $table->enum('project_source', $enumValues)->default('0')->nullable()->after('client_master');
                $table->enum('communication_type', $enumValues)->default('0')->nullable()->after('project_source');
                $table->enum('account_master', $enumValues)->default('0')->nullable()->after('communication_type');
                $table->enum('notes_management', $enumValues)->default('0')->nullable()->after('account_master');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropColumn('project_master');
                $table->dropColumn('client_master');
                $table->dropColumn('project_source');
                $table->dropColumn('communication_type');
                $table->dropColumn('account_master');
                $table->dropColumn('notes_management');
            });
        }
    }
};
