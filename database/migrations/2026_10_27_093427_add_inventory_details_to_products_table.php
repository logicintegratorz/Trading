<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('group_name', 80)->default('General')->after('sku')->index();
            $table->decimal('weight', 12, 3)->default(0)->after('name');
            $table->unsignedBigInteger('purchase_rate_cents')->default(0)->after('cost_cents');
            $table->unsignedBigInteger('sale_rate_cents')->default(0)->after('purchase_rate_cents');
            $table->unsignedBigInteger('opening_rate_cents')->default(0)->after('sale_rate_cents');
            $table->decimal('stock', 15, 3)->default(0)->change();
            $table->decimal('reorder_level', 15, 3)->default(0)->change();
        });

        Schema::table('records', function (Blueprint $table) {
            $table->decimal('quantity', 15, 3)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->change();
            $table->unsignedInteger('reorder_level')->default(10)->change();
            $table->dropIndex(['group_name']);
            $table->dropColumn([
                'group_name',
                'weight',
                'purchase_rate_cents',
                'sale_rate_cents',
                'opening_rate_cents',
            ]);
        });
    }
};
