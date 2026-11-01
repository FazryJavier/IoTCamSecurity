<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Creates the dashboard login account from SEED_USER_EMAIL / SEED_USER_PASSWORD.
     * When no password is configured a random one is generated and printed once.
     *
     * @return void
     */
    public function run()
    {
        $email = config('auth.seed_user.email');
        $password = config('auth.seed_user.password');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("User {$email} already exists, skipped.");
            return;
        }

        $generated = blank($password);
        if ($generated) {
            $password = Str::random(16);
        }

        User::create([
            'username' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->command?->info("Created user {$email}.");
        if ($generated) {
            $this->command?->warn("Generated password: {$password} (set SEED_USER_PASSWORD to choose your own)");
        }
    }
}
