<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_manager_can_create_update_and_assign_user_roles(): void
    {
        $manager = User::factory()->create();
        $managerRole = Role::create(['name' => 'Manager']);
        $managerRole->permissions()->attach(Permission::where('key', 'manage_access')->firstOrFail());
        $manager->roles()->attach($managerRole);
        $staff = Role::where('name', 'Staff')->firstOrFail();

        $this->actingAs($manager)->get(route('users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('users/index')->where('canImpersonate', false)->has('users.data', 1)->etc());
        $this->post(route('users.store'), [
            'name' => 'New User', 'email' => 'new@example.test', 'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!', 'role_ids' => [$staff->id], 'is_admin' => true,
        ])->assertSessionHasNoErrors();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('SecurePassword123!', $user->password));
        $this->assertEqualsCanonicalizing([$staff->id], $user->roles->pluck('id')->all());

        $this->put(route('users.update', $user), [
            'name' => 'Updated User', 'email' => 'updated@example.test',
            'role_ids' => [$managerRole->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Updated User', $user->fresh()->name);
        $this->assertSame('updated@example.test', $user->fresh()->email);
        $this->assertTrue($user->fresh()->hasPermission('manage_access'));
        $this->assertFalse($user->fresh()->roles()->whereKey($staff->id)->exists());
        $this->assertTrue(Hash::check('SecurePassword123!', $user->fresh()->password));
    }

    public function test_user_input_and_privileged_targets_are_restricted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $role = Role::where('name', 'Staff')->firstOrFail();
        $managerRole = Role::create(['name' => 'Manager']);
        $managerRole->permissions()->attach(Permission::where('key', 'manage_access')->firstOrFail());
        $manager->roles()->attach($managerRole);
        $payload = ['name' => 'Test', 'email' => 'test@example.test', 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!', 'role_ids' => [$role->id]];

        $this->actingAs($member)->get(route('users.index'))->assertForbidden();
        $this->post(route('users.store'), $payload)->assertForbidden();
        $this->put(route('users.update', $manager), $payload)->assertForbidden();
        $this->delete(route('users.destroy', $manager))->assertForbidden();
        $this->post(route('users.restore', $manager))->assertForbidden();

        $this->actingAs($manager)->post(route('users.store'), [...$payload, 'role_ids' => [999999]])->assertSessionHasErrors('role_ids.0');
        $this->put(route('users.update', $member), [...$payload, 'email' => $admin->email])->assertSessionHasErrors('email');
        $this->put(route('users.update', $admin), $payload)->assertForbidden();
        $this->put(route('access-control.users.roles.update', $admin), ['role_ids' => [$role->id]])->assertForbidden();
        $this->delete(route('users.destroy', $admin))->assertForbidden();
        $this->delete(route('users.destroy', $manager))->assertForbidden();
    }

    public function test_deactivation_preserves_reports_and_restore_reactivates_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'reporter@example.test']);
        $report = AccomplishmentReport::create([
            'user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026,
            'quantity_mode' => 'time', 'prepared_name' => 'Prepared', 'certified_name' => 'Certified', 'approved_name' => 'Approved',
        ]);

        $this->actingAs($admin)->delete(route('users.destroy', $user))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($user);
        $this->assertDatabaseHas('accomplishment_reports', ['id' => $report->id]);
        $this->assertSame($user->id, $report->fresh()->user->id);
        $this->get(route('users.index', ['search' => 'reporter@example.test']))->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)->where('users.data.0.id', $user->id)
            ->where('users.data.0.deleted_at', fn ($value) => $value !== null)->etc());
        $this->post(route('users.restore', $user->id))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_deactivated_user_cannot_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'SecurePassword123!']);
        $user->delete();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'SecurePassword123!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
