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
        Schema::table('leave_credits', function (Blueprint $table) {
            $table->dropColumn([
                'provisional_leave_taken',
                'provisional_extended_months',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_credits', function (Blueprint $table) {
            $table->decimal('provisional_leave_taken', 5, 2)
                ->default(0)
                ->after('provisional_leave_limit');

            $table->integer('provisional_extended_months')
                ->default(0)
                ->after('provisional_leave_taken');
        });
    }
};
