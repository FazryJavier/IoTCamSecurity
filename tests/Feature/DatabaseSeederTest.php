<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_login_user_once()
    {
        config([
            'auth.seed_user.email' => 'seed@seccam.test',
            'auth.seed_user.password' => 'seed-password',
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('email', 'seed@seccam.test')->count());
        $user = User::where('email', 'seed@seccam.test')->first();
        $this->assertSame('seed', $user->username);
        $this->assertTrue(Hash::check('seed-password', $user->password));
    }

    public function test_seeder_generates_password_when_not_configured()
    {
        config([
            'auth.seed_user.email' => 'gen@seccam.test',
            'auth.seed_user.password' => null,
        ]);

        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'gen@seccam.test')->firstOrFail();
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('', $user->password));
    }
}
