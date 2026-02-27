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
        if (Schema::hasTable('leave_credits') && Schema::hasColumn('leave_credits', 'paid_leaves')) {

            Schema::table('leave_credits', function (Blueprint $table) {
                $table->decimal('paid_leaves', 5, 2)->default(1)->change();
            });

        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_credits') && Schema::hasColumn('leave_credits', 'paid_leaves')) {

            Schema::table('leave_credits', function (Blueprint $table) {
                $table->integer('paid_leaves')->default(1)->change();
            });

        }
    }
};
