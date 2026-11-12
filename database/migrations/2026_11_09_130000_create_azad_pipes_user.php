<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $email = 'azadpipes@gmail.com';

        if (! DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')->insert([
                'name' => 'Azad Pipes',
                'email' => $email,
                'email_verified_at' => now(),
                'password' => '$2y$12$4/hiUnPPkCO5NFBcsaUJ6OhcBiS5NYFALtoduUH9ek70ZXLUxbJeG',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep user accounts intact when application migrations are rolled back.
    }
};
