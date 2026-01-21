<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    
    public function up(): void
    {
        Schema::table('project_activity_and_comments', function (Blueprint $table) {
            
            $table->unsignedBigInteger('project_id')->nullable()->change();
            $table->unsignedBigInteger('client_id')->nullable()->after('project_id');
        });
    }
   
    public function down(): void
    {
        Schema::table('project_activity_and_comments', function (Blueprint $table) {
            $table->dropColumn('client_id');
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
    }
};
