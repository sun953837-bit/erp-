<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 128);
            $table->string('biz_type', 64);
            $table->string('biz_id', 128)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('detail_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('biz_type', 64);
            $table->string('biz_id', 128);
            $table->unsignedTinyInteger('priority')->default(3);
            $table->string('title', 255);
            $table->text('content');
            $table->string('dedupe_key', 128)->nullable();
            $table->string('delivery_status', 32)->default('PENDING');
            $table->json('payload_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
    }
};
