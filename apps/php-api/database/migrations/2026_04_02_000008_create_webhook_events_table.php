<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('platform_code', 64);
            $table->string('event_key', 128);
            $table->string('signature', 256)->nullable();
            $table->string('status', 32)->default('RECEIVED');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['platform_code', 'event_key']);
            $table->index(['platform_code', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
