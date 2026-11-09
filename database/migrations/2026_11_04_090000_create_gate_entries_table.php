<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->string('party_name', 120);
            $table->string('vehicle_number', 60)->index();
            $table->string('type', 20)->index();
            $table->string('photo_path')->nullable();
            $table->date('entry_date')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_entries');
    }
};
