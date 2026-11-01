<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 40)->unique();
            $t->string('name', 120);
            $t->string('unit', 20)->default('pcs');
            $t->unsignedInteger('stock')->default(0);
            $t->unsignedInteger('reorder_level')->default(10);
            $t->unsignedBigInteger('cost_cents')->default(0);
            $t->timestamps();
        });
        Schema::create('records', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->string('type', 30)->index();
            $t->string('party', 120);
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('total_cents');
            $t->date('date')->index();
            $t->string('status', 20);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
        Schema::dropIfExists('products');
    }
};
