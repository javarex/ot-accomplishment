<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_summary_and_recent_reports_are_owned_by_the_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $owner->id, 'report_month' => 9, 'report_year' => 2026, 'status' => 'draft']);
        AccomplishmentReport::create(['user_id' => $other->id, 'report_month' => 9, 'report_year' => 2026, 'status' => 'generated']);

        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('summary.total', 1)
            ->where('summary.draft', 1)
            ->where('summary.generated', 0)
            ->where('recentReports.0.id', $report->id)
            ->has('recentReports', 1)
            ->etc());
    }
}
