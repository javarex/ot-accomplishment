<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_save_and_disable_net_pay_preference(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('profile.update'), [
            'name' => $admin->name, 'email' => $admin->email, 'show_report_net_pay' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->assertTrue($admin->fresh()->show_report_net_pay);
        $this->get(route('reports.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('showNetPay', true));
        $this->patch(route('profile.update'), [
            'name' => $admin->name, 'email' => $admin->email, 'show_report_net_pay' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($admin->fresh()->show_report_net_pay);
        $this->get(route('reports.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('showNetPay', false));
    }

    public function test_staff_cannot_enable_net_pay_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'show_report_net_pay' => '1',
        ])->assertSessionHasErrors('show_report_net_pay');

        $this->assertFalse($user->fresh()->show_report_net_pay);
    }

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_profile_update_does_not_change_existing_email_verification_timestamp()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_deactivate_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertSoftDeleted($user);
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
