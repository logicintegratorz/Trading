<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('products', function (Blueprint $table): void { $table->unsignedBigInteger('difference_cents')->default(0)->after('purchase_rate_cents'); }); }
    public function down(): void { Schema::table('products', function (Blueprint $table): void { $table->dropColumn('difference_cents'); }); }
};
