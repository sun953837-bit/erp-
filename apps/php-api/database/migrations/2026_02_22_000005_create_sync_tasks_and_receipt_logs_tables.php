<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_no', 64)->unique();
            $table->string('task_type', 64);
            $table->string('biz_type', 64);
            $table->string('biz_id', 128);
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('platform_code', 64);
            $table->string('site_code', 64);
            $table->string('status', 32)->default('PENDING');
            $table->string('idempotency_key', 128)->unique();
            $table->json('payload_json');
            $table->json('result_summary_json')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retry_count')->default(3);
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('created_by', 64)->nullable();
            $table->string('updated_by', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index(['task_type', 'platform_code']);
        });

        Schema::create('sync_receipt_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_task_id')->constrained('sync_tasks')->cascadeOnDelete();
            $table->string('request_id', 64);
            $table->string('phase', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('platform_code', 64);
            $table->string('endpoint', 255)->nullable();
            $table->boolean('success')->nullable();
            $table->boolean('accepted')->nullable();
            $table->boolean('final_result')->nullable();
            $table->string('external_id', 128)->nullable();
            $table->string('code', 64)->nullable();
            $table->text('message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sync_task_id', 'phase']);
            $table->index(['platform_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_receipt_logs');
        Schema::dropIfExists('sync_tasks');
    }
};
