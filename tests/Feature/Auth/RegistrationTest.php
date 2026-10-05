<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertDatabaseHas('users', ['username' => 'testuser']);
        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNull(User::where('email', 'test@example.com')->firstOrFail()->email_verified_at);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_registration_requires_a_unique_valid_username(): void
    {
        $user = User::factory()->create(['username' => 'existing']);
        $payload = ['name' => 'New User', 'email' => 'new@example.com',
            'password' => 'password', 'password_confirmation' => 'password'];
        foreach ([null, 'existing', 'invalid username'] as $username) {
            $this->post(route('register.store'), [...$payload, 'username' => $username])
                ->assertSessionHasErrors('username');
        }
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_email_verification_routes_are_disabled(): void
    {
        $this->assertFalse(Route::has('verification.notice'));
        $this->assertFalse(Route::has('verification.send'));
    }
}
