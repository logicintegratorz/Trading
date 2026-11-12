<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('pieces', 15, 3)->default(0)->change();
            $table->decimal('bundles', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('pieces')->default(0)->change();
            $table->unsignedInteger('bundles')->default(0)->change();
        });
    }
};
