<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_endpoint_requires_token()
    {
        $this->getJson('/api/users')
            ->assertStatus(401)
            ->assertExactJson([
                'code' => 401,
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => [['code' => 'unauthenticated', 'message' => 'A valid API token is required.']],
                'meta' => null,
            ]);
    }

    public function test_users_endpoint_returns_envelope_without_secrets()
    {
        $users = User::factory()->count(2)->create();
        Sanctum::actingAs($users->first());

        $response = $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('code', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Users retrieved.')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'username', 'email', 'created_at', 'updated_at']]]);

        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('password', $row);
            $this->assertArrayNotHasKey('remember_token', $row);
        }
    }
}
