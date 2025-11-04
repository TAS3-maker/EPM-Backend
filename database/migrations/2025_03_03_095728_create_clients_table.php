<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('client_type')->nullable();
            $table->enum('project_type', ['fixed', 'hourly'])->default('fixed');
            $table->string('upwork_id')->nullable()->unique();
            $table->string('contact_detail')->nullable();
            $table->string('hire_through')->nullable();
            $table->string('hire_on_id')->nullable()->default(null);
            $table->string('company_name')->nullable();
            $table->string('company_address')->nullable();
            $table->string('communication')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('clients');
    }
};
