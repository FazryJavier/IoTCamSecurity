<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $password = 'secret-pass'): User
    {
        return User::factory()->create([
            'email' => 'operator@seccam.test',
            'password' => Hash::make($password),
        ]);
    }

    public function test_guest_sees_login_form()
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_guest_is_redirected_away_from_dashboard()
    {
        $this->get('/home')->assertRedirect('/');
    }

    public function test_user_can_login_with_valid_credentials()
    {
        $user = $this->makeUser();

        $this->post('/', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password()
    {
        $user = $this->makeUser();

        $this->from('/')
            ->post('/', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/')
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_login_requires_valid_email()
    {
        $this->from('/')
            ->post('/', ['email' => 'not-an-email', 'password' => 'x'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_soft_deleted_user_cannot_login()
    {
        $user = $this->makeUser();
        $user->delete();

        $this->post('/', ['email' => $user->email, 'password' => 'secret-pass']);

        $this->assertGuest();
    }

    public function test_authenticated_user_is_redirected_from_login_page()
    {
        $this->actingAs($this->makeUser())->get('/')->assertRedirect('/home');
    }

    public function test_user_can_logout()
    {
        $this->actingAs($this->makeUser())
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_logout_requires_authentication()
    {
        $this->post('/logout')->assertRedirect('/');
    }
}
