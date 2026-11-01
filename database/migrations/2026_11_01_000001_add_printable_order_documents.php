<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('goods_number', 60)->nullable();
            $table->decimal('gst_percent', 5, 2)->default(18);
            $table->bigInteger('labour_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('commission_cents')->default(0);
            $table->bigInteger('freight_cents')->default(0);
            $table->bigInteger('other_charges_cents')->default(0);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('records')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 160);
            $table->decimal('weight_kg', 12, 3)->default(0);
            $table->bigInteger('difference_cents')->default(0);
            $table->bigInteger('base_rate_cents')->default(0);
            $table->decimal('quantity', 15, 3);
            $table->unsignedInteger('pieces')->default(0);
            $table->unsignedInteger('bundles')->default(0);
            $table->bigInteger('rate_cents');
            $table->bigInteger('amount_cents');
            $table->timestamps();
            $table->index(['record_id', 'product_id']);
        });

        DB::table('records')->whereIn('type', ['purchase-orders', 'sale-orders'])->update(['gst_percent' => 0]);
        DB::table('records')->whereIn('type', ['purchase-orders', 'sale-orders'])->orderBy('id')->each(function (object $record): void {
            $description = DB::table('group_heads')->where('id', $record->group_head_id)->value('name') ?: 'Order item';
            DB::table('order_items')->insert([
                'record_id' => $record->id, 'product_id' => $record->product_id, 'description' => $description,
                'quantity' => $record->quantity, 'rate_cents' => $record->unit_price_cents, 'amount_cents' => $record->total_cents,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::table('records', function (Blueprint $table) {
            $table->dropColumn(['goods_number', 'gst_percent', 'labour_cents', 'discount_cents', 'commission_cents', 'freight_cents', 'other_charges_cents']);
        });
    }
};
