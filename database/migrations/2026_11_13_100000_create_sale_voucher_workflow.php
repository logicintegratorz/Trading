<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('order_id')->nullable()->constrained('records')->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('goods_number', 60)->nullable();
            $table->string('status', 20)->default('Draft');
            $table->timestamps();
            $table->index(['status', 'date']);
        });

        Schema::create('sale_voucher_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sauda_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('fulfilled_quantity', 15, 3)->default(0);
            $table->unsignedBigInteger('rate_cents')->default(0);
            $table->timestamps();
            $table->unique(['sale_voucher_id', 'sauda_id']);
        });

        Schema::table('records', function (Blueprint $table) {
            $table->foreignId('sale_voucher_id')->nullable()->after('sauda_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('records', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_voucher_id'));
        Schema::dropIfExists('sale_voucher_items');
        Schema::dropIfExists('sale_vouchers');
    }
};
