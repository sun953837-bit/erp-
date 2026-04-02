<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products_spu', function (Blueprint $table) {
            $table->id();
            $table->string('spu_code', 64)->unique();
            $table->string('title', 255);
            $table->string('brand', 128);
            $table->string('category_name', 128);
            $table->string('status', 32)->default('ACTIVE');
            $table->string('source_of_truth', 32)->default('system');
            $table->timestamps();
        });

        Schema::create('products_sku', function (Blueprint $table) {
            $table->id();
            $table->string('sku_code', 64)->unique();
            $table->foreignId('spu_id')->constrained('products_spu')->cascadeOnDelete();
            $table->string('sku_name', 255);
            $table->json('specs_json');
            $table->string('barcode', 64)->nullable();
            $table->decimal('cost_price', 12, 2);
            $table->string('cost_currency', 16);
            $table->decimal('retail_price', 12, 2);
            $table->string('retail_currency', 16);
            $table->decimal('weight', 10, 3)->nullable();
            $table->json('size_json')->nullable();
            $table->string('status', 32)->default('ACTIVE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products_sku');
        Schema::dropIfExists('products_spu');
    }
};
