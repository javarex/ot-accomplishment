<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_impersonate_a_member_and_return(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        $this->actingAs($admin)->post(route('impersonation.store', $member))
            ->assertRedirect(route('dashboard'))->assertSessionHas('impersonator_id', $admin->id);
        $this->assertAuthenticatedAs($member);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.name', $member->name)
            ->where('permissions.manageAccess', false)->etc());
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('access-control.index'))->assertForbidden();
        $this->post(route('impersonation.store', $admin))->assertForbidden();

        $this->delete(route('impersonation.destroy'))->assertRedirect(route('users.index'))
            ->assertSessionMissing('impersonator_id');
        $this->assertAuthenticatedAs($admin);
        $this->get(route('users.index'))->assertOk();
        $this->get(route('access-control.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('access-control/index')->where('permissions.manageAccess', true)->etc());
    }

    public function test_inertia_impersonation_switches_force_a_fresh_page_for_each_identity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        $this->actingAs($admin)->withHeader('X-Inertia', 'true')
            ->post(route('impersonation.store', $member))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('dashboard'));
        $this->assertAuthenticatedAs($member);
        $this->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $member->id)
            ->where('impersonation.name', $member->name)->etc());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $member->id)->etc());

        $this->delete(route('impersonation.destroy'))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $admin->id)->where('impersonation', null)->etc());
    }

    public function test_only_admin_can_impersonate_and_targets_must_be_active_non_admin_users(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $anotherAdmin = User::factory()->create(['is_admin' => true]);
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $inactive = User::factory()->create();
        $inactive->delete();
        $managerRole = Role::create(['name' => 'Manager']);
        $managerRole->permissions()->attach(Permission::where('key', 'manage_access')->firstOrFail());
        $manager->roles()->attach($managerRole);

        $this->actingAs($manager)->post(route('impersonation.store', $member))->assertForbidden();
        $this->delete(route('impersonation.destroy'))->assertForbidden();
        $this->actingAs($admin)->post(route('impersonation.store', $admin))->assertForbidden();
        $this->post(route('impersonation.store', $anotherAdmin))->assertForbidden();
        $this->post(route('impersonation.store', $inactive))->assertNotFound();
    }
}
