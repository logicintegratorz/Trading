<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_group_name_name_unique');
            $table->string('product_name_key', 120)->nullable()->unique();
        });

        DB::table('products')->orderBy('id')->each(function (object $product): void {
            DB::table('products')->where('id', $product->id)->update(['product_name_key' => mb_strtolower(trim($product->name))]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_product_name_key_unique');
            $table->dropColumn('product_name_key');
            $table->unique(['group_name', 'name'], 'products_group_name_name_unique');
        });
    }
};
