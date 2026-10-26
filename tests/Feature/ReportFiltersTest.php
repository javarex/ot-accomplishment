<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\Signatory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_enable_net_pay_for_time_based_reports(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'show_report_net_pay' => true]);
        $owner = User::factory()->create();
        foreach ([
            ['quantity_mode' => 'time', 'hourly_rate' => 100],
            ['quantity_mode' => 'time', 'is_jo' => true, 'daily_rate' => 800, 'jo_tax_percent' => 10],
            ['quantity_mode' => 'custom', 'hourly_rate' => 100],
            ['quantity_mode' => 'time'],
        ] as $attributes) {
            $report = AccomplishmentReport::create(['user_id' => $owner->id, 'report_year' => 2026, 'report_month' => 9, ...$attributes]);
            $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity_mode' => $attributes['quantity_mode'], 'time_minutes' => 60, 'quantity' => '1 hour', 'task_accomplished' => 'Completed work']);
        }

        $this->actingAs($admin)->get(route('reports.index', ['year' => 2026, 'month' => 9, 'show_net_pay' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showNetPay', true)
                ->where('reports.data.0.net_pay_cents', null)
                ->where('reports.data.1.net_pay_cents', null)
                ->where('reports.data.2.net_pay_cents', 9000)
                ->where('reports.data.3.net_pay_cents', 10000)
                ->missing('reports.data.3.entries')
                ->missing('reports.data.3.hourly_rate'));
    }

    public function test_net_pay_is_off_by_default_and_cannot_be_enabled_by_staff(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $staff = User::factory()->create();
        AccomplishmentReport::create(['user_id' => $staff->id, 'report_year' => 2026, 'report_month' => 9, 'quantity_mode' => 'time', 'hourly_rate' => 100]);

        $this->actingAs($admin)->get(route('reports.index', ['year' => 2026, 'month' => 9]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showNetPay', false)
                ->missing('reports.data.0.net_pay_cents')
                ->missing('reports.data.0.hourly_rate'));
        $this->actingAs($staff)->get(route('reports.index', ['year' => 2026, 'month' => 9, 'show_net_pay' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showNetPay', false)
                ->missing('reports.data.0.net_pay_cents')
                ->missing('reports.data.0.hourly_rate')
                ->missing('reports.data.0.entries'));
    }

    public function test_saved_net_pay_preference_applies_to_later_pages(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'show_report_net_pay' => true]);
        for ($number = 0; $number < 16; $number++) {
            AccomplishmentReport::create(['user_id' => $admin->id, 'report_year' => 2026, 'report_month' => 9]);
        }

        $this->actingAs($admin)->get(route('reports.index', ['year' => 2026, 'month' => 9, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showNetPay', true)
                ->where('reports.current_page', 2)
                ->has('reports.data', 1));
    }

    public function test_default_period_lists_reports_from_all_users(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 18));
        $user = User::factory()->create();
        $other = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_year' => 2026, 'report_month' => 10]);
        $otherReport = AccomplishmentReport::create(['user_id' => $other->id, 'prepared_name' => $user->name, 'report_year' => 2026, 'report_month' => 10]);
        AccomplishmentReport::create(['user_id' => $user->id, 'report_year' => 2025, 'report_month' => 10]);
        AccomplishmentReport::create(['user_id' => $user->id, 'report_year' => 2026, 'report_month' => 9]);

        $this->actingAs($user)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('filters.year', 2026)->where('filters.month', 10)
            ->has('reports.data', 2)->where('reports.data.0.id', $otherReport->id)->where('reports.data.1.id', $report->id));
    }

    public function test_super_admin_can_filter_everyones_reports_and_pagination_keeps_the_period(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        foreach (range(1, 16) as $number) {
            AccomplishmentReport::create(['user_id' => $number % 2 === 0 ? $admin->id : $owner->id, 'prepared_name' => 'Shared Preparer', 'report_year' => 2025, 'report_month' => 9]);
        }
        AccomplishmentReport::create(['user_id' => $owner->id, 'report_year' => 2026, 'report_month' => 9]);

        $this->actingAs($admin)->get(route('reports.index', ['year' => 2025, 'month' => 9, 'search' => 'Shared']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reports.total', 16)->has('reports.data', 15)
                ->where('filters.year', 2025)->where('filters.month', 9)
                ->where('reports.next_page_url', fn ($url) => str_contains($url, 'year=2025') && str_contains($url, 'month=9') && str_contains($url, 'search=Shared')));
    }

    public function test_search_matches_partial_owner_and_displayed_preparer_names(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create(['name' => 'Alex Santos']);
        $signatory = Signatory::create(['user_id' => $owner->id, 'name' => 'Maria Cruz', 'position' => 'Staff', 'signatory_type' => 'prepared_by']);
        $report = AccomplishmentReport::create(['user_id' => $owner->id, 'report_year' => 2026, 'report_month' => 9, 'prepared_by_id' => $signatory->id]);
        AccomplishmentReport::create(['user_id' => $admin->id, 'report_year' => 2025, 'report_month' => 9, 'prepared_name' => 'Maria']);
        AccomplishmentReport::create(['user_id' => $admin->id, 'report_year' => 2026, 'report_month' => 8, 'prepared_name' => 'Maria']);

        foreach (['sant', 'cruz'] as $search) {
            $this->actingAs($admin)->get(route('reports.index', ['year' => 2026, 'month' => 9, 'search' => $search]))
                ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.id', $report->id));
        }

        $report->update(['prepared_name' => 'Custom Preparer']);
        $this->get(route('reports.index', ['year' => 2026, 'month' => 9, 'search' => 'custom']))
            ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.id', $report->id));
        $this->get(route('reports.index', ['year' => 2026, 'month' => 9, 'search' => 'cruz']))
            ->assertInertia(fn (Assert $page) => $page->has('reports.data', 0));
        $this->actingAs($admin->fresh()->forceFill(['is_admin' => false]))
            ->get(route('reports.index', ['year' => 2026, 'month' => 9, 'search' => 'custom']))
            ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.id', $report->id));
    }

    public function test_invalid_period_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->getJson(route('reports.index', ['year' => 'bad', 'month' => 13]))
            ->assertUnprocessable()->assertJsonValidationErrors(['year', 'month']);
    }
}
