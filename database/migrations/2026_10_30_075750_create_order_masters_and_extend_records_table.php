<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_heads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });

        Schema::table('records', function (Blueprint $table) {
            $table->foreignId('group_head_id')->nullable()->after('type')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->after('group_head_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->change();
        });

        foreach (DB::table('products')->select('group_name')->distinct()->pluck('group_name') as $name) {
            DB::table('group_heads')->insertOrIgnore(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (DB::table('records')->select('party')->distinct()->pluck('party') as $name) {
            DB::table('parties')->insertOrIgnore(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('records')->orderBy('id')->each(function (object $record): void {
            $partyId = DB::table('parties')->where('name', $record->party)->value('id');
            $groupName = $record->product_id ? DB::table('products')->where('id', $record->product_id)->value('group_name') : null;
            $groupHeadId = $groupName ? DB::table('group_heads')->where('name', $groupName)->value('id') : null;
            DB::table('records')->where('id', $record->id)->update(['group_head_id' => $groupHeadId, 'party_id' => $partyId]);
        });
    }

    public function down(): void
    {
        DB::table('records')->whereNull('product_id')->delete();
        Schema::table('records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_head_id');
            $table->dropConstrainedForeignId('party_id');
            $table->foreignId('product_id')->nullable(false)->change();
        });
        Schema::dropIfExists('parties');
        Schema::dropIfExists('group_heads');
    }
};
