<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
            $table->renameColumn('tracking_id', 'tracking_id_old');
        });

        Schema::table('project_relations', function (Blueprint $table) {
            $table->json('tracking_id')->nullable()->after('tracking_id_old');
        });

        DB::table('project_relations')
            ->whereNotNull('tracking_id_old')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('project_relations')
                        ->where('id', $row->id)
                        ->update([
                            'tracking_id' => json_encode([(int) $row->tracking_id_old])
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('project_relations', function (Blueprint $table) {
            $table->dropColumn('tracking_id');
        });

        Schema::table('project_relations', function (Blueprint $table) {
            $table->renameColumn('tracking_id_old', 'tracking_id');
        });
    }
};
