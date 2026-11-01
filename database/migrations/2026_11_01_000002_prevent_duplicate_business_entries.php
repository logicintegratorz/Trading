<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unique(['group_name', 'name'], 'products_group_name_name_unique');
        });
        Schema::table('saudas', function (Blueprint $table) {
            $table->unique(['type', 'party_id', 'product_id', 'date', 'quantity', 'rate_cents'], 'saudas_duplicate_deal_unique');
        });
        Schema::table('records', function (Blueprint $table) {
            $table->unique(['type', 'sauda_id', 'date', 'quantity'], 'records_duplicate_stock_entry_unique');
            $table->string('order_fingerprint', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropUnique('records_duplicate_stock_entry_unique');
            $table->dropUnique('records_order_fingerprint_unique');
            $table->dropColumn('order_fingerprint');
        });
        Schema::table('saudas', function (Blueprint $table) {
            $table->dropUnique('saudas_duplicate_deal_unique');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_group_name_name_unique');
        });
    }
};
