<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('voucher_reference', 40)->nullable()->after('reference')->index();
            $table->bigInteger('difference_cents')->default(0)->after('unit_price_cents');
            $table->bigInteger('base_rate_cents')->default(0)->after('difference_cents');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex(['voucher_reference']);
            $table->dropColumn(['voucher_reference', 'difference_cents', 'base_rate_cents']);
        });
    }
};
