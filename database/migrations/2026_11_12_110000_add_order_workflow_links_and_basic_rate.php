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
            $table->bigInteger('basic_rate_cents')->default(0);
        });

        Schema::table('saudas', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained('records')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->unique('order_item_id');
        });

        DB::table('records')->whereIn('type', ['purchase-orders', 'sale-orders'])->orderBy('id')->each(function (object $order): void {
            $rate = DB::table('order_items')->where('record_id', $order->id)->value('base_rate_cents');
            DB::table('records')->where('id', $order->id)->update(['basic_rate_cents' => $rate ?? 0]);
        });
    }

    public function down(): void
    {
        Schema::table('saudas', function (Blueprint $table) {
            $table->dropUnique(['order_item_id']);
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::table('records', function (Blueprint $table) {
            $table->dropColumn('basic_rate_cents');
        });
    }
};
