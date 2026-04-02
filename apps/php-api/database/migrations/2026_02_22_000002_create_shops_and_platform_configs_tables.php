<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('shop_code', 64)->unique();
            $table->string('shop_name', 255);
            $table->string('platform_code', 64);
            $table->string('site_code', 64);
            $table->string('currency', 16);
            $table->string('timezone', 64);
            $table->string('status', 32)->default('ACTIVE');
            $table->string('owner_name', 64);
            $table->string('owner_phone', 32);
            $table->timestamps();
            $table->index(['platform_code', 'site_code']);
        });

        Schema::create('shop_platform_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('auth_mode', 32);
            $table->text('app_key_encrypted')->nullable();
            $table->text('app_secret_encrypted')->nullable();
            $table->string('client_id', 128)->nullable();
            $table->text('client_secret_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->unsignedInteger('key_version')->default(1);
            $table->boolean('is_configured')->default(false);
            $table->string('status', 32)->default('PENDING');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_platform_configs');
        Schema::dropIfExists('shops');
    }
};
