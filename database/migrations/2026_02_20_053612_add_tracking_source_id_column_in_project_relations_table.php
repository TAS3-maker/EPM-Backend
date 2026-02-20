<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
             $table->unsignedBigInteger('tracking_source_id')
                ->nullable()
                ->after('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
             $table->dropColumn([
                'tracking_source_id'
            ]);
        });
    }
};
