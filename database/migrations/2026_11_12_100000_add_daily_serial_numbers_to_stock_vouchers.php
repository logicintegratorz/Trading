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
            $table->unsignedInteger('voucher_number')->nullable()->after('voucher_reference')->index();
        });

        Schema::create('daily_voucher_sequences', function (Blueprint $table) {
            $table->date('sequence_date')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        // Preserve all current records and give historical vouchers a readable
        // per-day serial. Lines in one saved voucher receive the same number.
        $serialByDate = [];
        $voucherNumbers = [];
        $records = DB::table('records')
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'date', 'reference', 'voucher_reference']);

        foreach ($records as $record) {
            $voucherKey = $record->date.'|'.($record->voucher_reference ?: $record->reference);
            if (! isset($voucherNumbers[$voucherKey])) {
                $serialByDate[$record->date] = ($serialByDate[$record->date] ?? 0) + 1;
                $voucherNumbers[$voucherKey] = $serialByDate[$record->date];
            }

            DB::table('records')->where('id', $record->id)->update([
                'voucher_number' => $voucherNumbers[$voucherKey],
            ]);
        }

        $now = now();
        foreach ($serialByDate as $date => $lastNumber) {
            DB::table('daily_voucher_sequences')->insert([
                'sequence_date' => $date,
                'last_number' => $lastNumber,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_voucher_sequences');

        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex(['voucher_number']);
            $table->dropColumn('voucher_number');
        });
    }
};
