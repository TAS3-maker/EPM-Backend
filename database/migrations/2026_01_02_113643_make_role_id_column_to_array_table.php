<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
           
            $table->renameColumn('role_id', 'role_id_old');
        });
        Schema::table('users', function (Blueprint $table) {
          
            $table->json('role_id')->nullable()->after('role_id_old');
        });
        DB::table('users')
            ->whereNotNull('role_id_old')
            ->update([
                'role_id' => DB::raw("JSON_ARRAY(role_id_old)")
            ]);
    }
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_id');
            $table->renameColumn('role_id_old', 'role_id');
        });
    }
};