<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $enumValues = ['0', '1', '2', '3'];
            $table->enum('standup_sheet', $enumValues)->default('0')->nullable()->after('offline_hours');
        });

    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('standup_sheet');
        });
    }
};
