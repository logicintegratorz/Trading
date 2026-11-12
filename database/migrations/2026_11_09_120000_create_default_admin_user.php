<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $email = 'admin@tradeflow.local';

        if (! DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')->insert([
                'name' => 'Tradeflow Administrator',
                'email' => $email,
                'email_verified_at' => now(),
                // Hash for the temporary installation password supplied with the deployment notes.
                'password' => '$2y$12$OIbPDJJjOaUnn1uma.1OueE3I5KZ8ST3xOD/QKFyXiIlv/mjBsONO',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally keep the account: rolling back application code must not delete a live user's access.
    }
};
