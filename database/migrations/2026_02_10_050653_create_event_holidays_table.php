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
        Schema::create('event_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('type', [
                'Full Holiday',
                'Short Holiday',
                'Half Holiday',
                'Multiple Holiday'
            ]);
            $table->string('description');

            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();

            $table->enum('halfday_period', ['morning', 'afternoon'])->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_holidays');
    }
};
