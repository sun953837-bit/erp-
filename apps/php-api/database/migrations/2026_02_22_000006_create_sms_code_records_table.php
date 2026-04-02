<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sms_code_records', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32);
            $table->string('purpose', 64);
            $table->string('code_hash', 128);
            $table->string('salt', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('send_status', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['phone', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_code_records');
    }
};
