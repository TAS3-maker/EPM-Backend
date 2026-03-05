<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $enumValues = ['0', '1', '2', '3'];
            $table->enum('event_management', $enumValues)->default('0')->nullable()->after('master_reporting');
            $table->enum('leave_credit', $enumValues)->default('0')->nullable()->after('event_management');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('event_management');
            $table->dropColumn('leave_credit');
        });
    }
};
