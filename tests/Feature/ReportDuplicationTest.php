<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_duplicate_a_generated_report_as_an_independent_draft(): void
    {
        $owner = User::factory()->create();
        $report = AccomplishmentReport::create([
            'user_id' => $owner->id, 'report_month' => 9, 'report_year' => 2026,
            'quantity_mode' => 'time', 'hourly_rate' => '100.00',
            'prepared_name' => 'Prepared', 'certified_name' => 'Certified', 'approved_name' => 'Approved',
            'status' => AccomplishmentReport::GENERATED, 'generated_at' => now(),
        ]);
        $entry = $report->entries()->create([
            'accomplishment_date' => '2026-09-04', 'quantity_mode' => 'time', 'time_minutes' => 135,
            'quantity' => '2.8125', 'task_accomplished' => 'Prepared the report.', 'sort_order' => 2,
        ]);

        $response = $this->actingAs($owner)->post(route('reports.duplicate', $report));
        $copy = AccomplishmentReport::where('id', '!=', $report->id)->sole();
        $response->assertRedirect(route('reports.edit', $copy));
        $this->assertSame($owner->id, $copy->user_id);
        $this->assertSame(AccomplishmentReport::DRAFT, $copy->status);
        $this->assertNull($copy->generated_at);
        $this->assertSame($report->hourly_rate, $copy->hourly_rate);
        $this->assertSame($report->report_month, $copy->report_month);
        $this->assertSame($report->approved_name, $copy->approved_name);
        $copiedEntry = $copy->entries()->sole();
        $this->assertNotSame($entry->id, $copiedEntry->id);
        $this->assertSame(135, $copiedEntry->time_minutes);
        $this->assertSame($entry->quantity, $copiedEntry->quantity);
        $this->assertSame(2, $copiedEntry->sort_order);
        $this->assertNull($copiedEntry->dtr_entry_id);
        $copiedEntry->update(['task_accomplished' => 'Changed copy.']);
        $this->assertSame('Prepared the report.', $entry->fresh()->task_accomplished);
        $this->assertSame(AccomplishmentReport::GENERATED, $report->fresh()->status);
    }

    public function test_other_users_including_admins_cannot_duplicate_someone_elses_report(): void
    {
        $owner = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $owner->id, 'report_month' => 9, 'report_year' => 2026]);
        foreach ([false, true] as $isAdmin) {
            $other = User::factory()->create(['is_admin' => $isAdmin]);
            $this->actingAs($other)->post(route('reports.duplicate', $report))->assertForbidden();
        }
        $this->assertDatabaseCount('accomplishment_reports', 1);
    }
}
