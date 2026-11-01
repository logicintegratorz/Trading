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
        Schema::create('saudas', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('type', 20);
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('fulfilled_quantity', 15, 3)->default(0);
            $table->unsignedBigInteger('rate_cents');
            $table->date('date');
            $table->string('status', 20)->default('Open');
            $table->timestamps();
            $table->index(['type', 'status', 'date']);
        });

        Schema::table('records', function (Blueprint $table) {
            $table->foreignId('sauda_id')->nullable()->after('party_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sauda_id');
        });
        Schema::dropIfExists('saudas');
    }
};
