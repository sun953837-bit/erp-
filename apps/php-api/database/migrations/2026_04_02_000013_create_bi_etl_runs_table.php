<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bi_etl_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 64)->unique();
            $table->string('last_mode', 32)->nullable();
            $table->unsignedInteger('last_window_days')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->json('last_counts_json')->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_etl_runs');
    }
};
