<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ManageAdminUser extends Command
{
    protected $signature = 'tradeflow:admin
        {--email= : Administrator email address}
        {--name= : Administrator display name}
        {--password= : New password (use the secure prompt when omitted)}';

    protected $description = 'Create or securely reset the Tradeflow administrator login';

    public function handle(): int
    {
        $email = strtolower(trim($this->option('email') ?: $this->ask('Administrator email', 'admin@tradeflow.local')));
        $name = trim($this->option('name') ?: $this->ask('Administrator name', 'Tradeflow Administrator'));
        $password = $this->option('password') ?: $this->secret('New password (minimum 12 characters)');

        if (! $password) {
            $this->error('A password is required.');
            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'email_verified_at' => now()]
        );

        $this->info("Administrator login saved for {$email}.");
        return self::SUCCESS;
    }
}
