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
        Schema::table('clients_master', function (Blueprint $table) {
            $table->string('client_email')->nullable()->after('client_name');
            $table->string('client_number')->nullable()->after('client_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients_master', function (Blueprint $table) {
            $table->dropColumn(['client_email', 'client_number']);
        });
    }
};
