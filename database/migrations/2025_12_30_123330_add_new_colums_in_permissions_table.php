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
                $table->enum('team_reporting', $enumValues)->default('0')->nullable()->after('notes_management');
                $table->enum('leave_reporting', $enumValues)->default('0')->nullable()->after('team_reporting');
                $table->enum('previous_sheets', $enumValues)->default('0')->nullable()->after('leave_reporting');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropColumn('team_reporting');
                $table->dropColumn('leave_reporting');
                $table->dropColumn('previous_sheets');
            });
        }
    }
};
