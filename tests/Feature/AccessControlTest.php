<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_roles_permissions_and_user_assignments(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        $viewPermission = Permission::where('key', 'view_ot_computation')->firstOrFail();
        $managePermission = Permission::where('key', 'manage_access')->firstOrFail();

        $this->actingAs($admin)->get(route('access-control.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('access-control/index')->has('users', 2)->has('availablePermissions', 3)
            ->where('permissions.manageAccess', true)->etc());
        $this->post(route('access-control.roles.store'), [
            'name' => 'Payroll Viewer', 'permission_ids' => [$viewPermission->id],
        ])->assertSessionHasNoErrors();
        $role = Role::where('name', 'Payroll Viewer')->firstOrFail();
        $this->assertTrue($role->permissions()->whereKey($viewPermission->id)->exists());
        $this->put(route('access-control.users.roles.update', $member), [
            'role_ids' => [Role::where('name', 'Staff')->firstOrFail()->id, $role->id],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($member->fresh()->hasPermission('view_ot_computation'));
        $this->put(route('access-control.roles.update', $role), [
            'name' => 'Access Manager', 'permission_ids' => [$managePermission->id],
        ])->assertSessionHasNoErrors();
        $this->assertFalse($member->fresh()->hasPermission('view_ot_computation'));
        $this->assertTrue($member->fresh()->hasPermission('manage_access'));
        $this->actingAs($member)->get(route('access-control.index'))->assertOk();
        $this->actingAs($admin)->delete(route('access-control.roles.destroy', $role))->assertSessionHasNoErrors();
        $this->assertFalse($member->fresh()->hasPermission('manage_access'));
    }

    public function test_non_manager_cannot_read_or_change_access_settings(): void
    {
        $user = User::factory()->create();
        $role = Role::where('name', 'Staff')->firstOrFail();
        $this->actingAs($user)->get(route('access-control.index'))->assertForbidden();
        $this->post(route('access-control.roles.store'), ['name' => 'Finance', 'permission_ids' => []])->assertForbidden();
        $this->put(route('access-control.roles.update', $role), ['name' => 'Finance', 'permission_ids' => []])->assertForbidden();
        $this->delete(route('access-control.roles.destroy', $role))->assertForbidden();
        $this->put(route('access-control.users.roles.update', $user), ['role_ids' => []])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'Finance']);
    }

    public function test_invalid_role_and_user_assignments_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $this->actingAs($admin)->post(route('access-control.roles.store'), [
            'name' => 'Finance', 'permission_ids' => [999999],
        ])->assertSessionHasErrors('permission_ids.0');
        $this->put(route('access-control.users.roles.update', $user), ['role_ids' => [999999]])
            ->assertSessionHasErrors('role_ids.0');
        $this->delete(route('access-control.roles.destroy', Role::where('name', 'Staff')->firstOrFail()))->assertStatus(422);
    }

    public function test_non_viewer_receives_no_rate_or_pay_in_pages_or_exports(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);
        $this->actingAs($user)->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('canViewComputation', false)
            ->where('overtimePay', null)
            ->missing('report.hourly_rate')
            ->missing('report.entries.0.hourly_rate')->etc());
        $this->get(route('reports.edit', $report))->assertInertia(fn (Assert $page) => $page
            ->where('canViewComputation', false)->missing('report.hourly_rate')->etc());
        $this->get(route('reports.preview', $report))->assertOk()->assertDontSee('HOURLY RATE')->assertDontSee('Net OT pay')->assertDontSee('100.00');
        $this->put(route('reports.update', $report), ['hourly_rate' => '999.00'])->assertForbidden();
        $this->put(route('reports.hourly-rate.update', $report), ['hourly_rate' => '999.00'])->assertForbidden();
        $pdf = $this->post(route('reports.generate', $report))->assertOk();
        $pdfText = (new Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringNotContainsString('Net OT pay', $pdfText);
        $this->assertStringNotContainsString('100.00', $pdfText);
        $docx = $this->post(route('reports.generate-docx', $report))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'access-docx-');
        file_put_contents($path, $docx->getContent());
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringNotContainsString('HOURLY RATE', $xml);
            $this->assertStringNotContainsString('Net OT pay', $xml);
            $this->assertStringNotContainsString('100.00', $xml);
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_viewer_role_grants_computation_visibility_without_access_management(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $report = $this->report($user);
        $role = Role::create(['name' => 'Payroll Viewer']);
        $role->permissions()->sync([Permission::where('key', 'view_ot_computation')->firstOrFail()->id]);
        $user->roles()->attach($role);
        $this->actingAs($user)->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('canViewComputation', true)
            ->where('canSetHourlyRate', false)
            ->where('report.hourly_rate', '100.00')
            ->where('overtimePay.net_cents', 10000)->etc());
        $this->get(route('access-control.index'))->assertForbidden();
        $this->put(route('reports.hourly-rate.update', $report), ['hourly_rate' => '200.00'])->assertForbidden();
        $this->put(route('reports.update', $report), ['hourly_rate' => '200.00'])->assertForbidden();
        $this->actingAs($admin)->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('canViewComputation', true)->where('overtimePay.net_cents', 10000)->etc());
        $this->put(route('reports.hourly-rate.update', $report), ['hourly_rate' => '200.00'])->assertSessionHasNoErrors();
        $this->assertSame('200.00', $report->fresh()->hourly_rate);
    }

    public function test_rate_editor_permission_can_update_rate_without_access_management(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);
        $role = Role::create(['name' => 'Payroll Editor']);
        $role->permissions()->sync([Permission::where('key', 'edit_ot_computation')->firstOrFail()->id]);
        $user->roles()->attach($role);
        $this->actingAs($user)->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('canViewComputation', true)->where('canSetHourlyRate', true)->etc());
        $this->put(route('reports.hourly-rate.update', $report), ['hourly_rate' => '300.00'])->assertSessionHasNoErrors();
        $this->assertSame('300.00', $report->fresh()->hourly_rate);
        $this->get(route('access-control.index'))->assertForbidden();
    }

    private function report(User $user): AccomplishmentReport
    {
        $report = AccomplishmentReport::create([
            'user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026,
            'quantity_mode' => 'time', 'hourly_rate' => '100.00',
            'prepared_name' => 'Prepared', 'certified_name' => 'Certified', 'approved_name' => 'Approved',
        ]);
        $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity_mode' => 'time', 'time_minutes' => 60, 'quantity' => '1 hours', 'task_accomplished' => 'Prepared the report.']);

        return $report;
    }
}
