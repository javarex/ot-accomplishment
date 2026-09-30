<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\Signatory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class OvertimePayTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_rate_applies_to_all_records_and_exports_and_ignores_row_rates(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['entries'] = [
            ['accomplishment_date' => '2026-09-04', 'time_minutes' => 110, 'hourly_rate' => '100.00', 'task_accomplished' => 'Friday work'],
            ['accomplishment_date' => '2026-09-07', 'time_minutes' => 100, 'hourly_rate' => '200.00', 'task_accomplished' => 'Monday work'],
            ['accomplishment_date' => '2026-09-05', 'time_minutes' => 110, 'hourly_rate' => '100.00', 'task_accomplished' => 'Saturday work'],
            ['accomplishment_date' => '2026-09-06', 'time_minutes' => 100, 'hourly_rate' => '200.00', 'task_accomplished' => 'Sunday work'],
        ];
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $this->assertDatabaseHas('accomplishment_reports', ['id' => $report->id, 'hourly_rate' => 100]);
        $this->get(route('reports.edit', $report))->assertInertia(fn (Assert $page) => $page
            ->where('report.hourly_rate', '100.00')->etc());
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.weekday.hours', 2)
            ->where('overtimePay.weekday.minutes', 90)
            ->where('overtimePay.weekday.total_minutes', 210)
            ->where('overtimePay.weekday.gross_cents', 43750)
            ->where('overtimePay.weekend.gross_cents', 52500)
            ->where('overtimePay.gross_cents', 96250)
            ->where('overtimePay.deduction_cents', 19250)
            ->where('overtimePay.net_cents', 77000)
            ->where('overtimePay.complete', true)->etc());
        $this->get(route('reports.preview', $report))->assertOk()->assertSee('HOURLY RATE')->assertSee('770.00');
        $pdf = $this->post(route('reports.generate', $report))->assertOk();
        $pdfText = preg_replace('/\s+/', ' ', (new Parser)->parseContent($pdf->getContent())->getText());
        $this->assertStringContainsString('HOURLY RATE', $pdfText);
        $this->assertStringContainsString('770.00', $pdfText);
        $docx = $this->post(route('reports.generate-docx', $report))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'ot-pay-docx-');
        file_put_contents($path, $docx->getContent());
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('HOURLY RATE', $xml);
            $this->assertStringContainsString('100.00', $xml);
            $this->assertStringContainsString('770.00', $xml);
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_rate_update_persists_and_recalculates_pay(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $payload['entries'][0]['id'] = $report->entries()->firstOrFail()->id;
        $payload['hourly_rate'] = '250.00';
        $this->put(route('reports.update', $report), $payload)->assertSessionHasNoErrors();
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('report.hourly_rate', '250.00')
            ->where('overtimePay.gross_cents', 31250)
            ->where('overtimePay.net_cents', 25000)->etc());
    }

    public function test_money_is_rounded_after_aggregating_time_instead_of_per_record(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['hourly_rate'] = '0.25';
        $payload['entries'] = [
            ['accomplishment_date' => '2026-09-01', 'time_minutes' => 1, 'hourly_rate' => '0.25', 'task_accomplished' => 'First work'],
            ['accomplishment_date' => '2026-09-02', 'time_minutes' => 1, 'hourly_rate' => '0.25', 'task_accomplished' => 'Second work'],
        ];
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $this->get(route('reports.show', AccomplishmentReport::firstOrFail()))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.gross_cents', 1)
            ->where('overtimePay.deduction_cents', 0)
            ->where('overtimePay.net_cents', 1)->etc());
    }

    #[TestWith(['-1'])]
    #[TestWith(['1.001'])]
    #[TestWith(['abc'])]
    #[TestWith(['100000000'])]
    public function test_invalid_rates_are_rejected(string $rate): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['hourly_rate'] = $rate;
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasErrors('hourly_rate');
        $this->assertDatabaseCount('accomplishment_reports', 0);
    }

    public function test_missing_rate_can_be_saved_as_draft_but_cannot_be_finalized_or_exported(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['hourly_rate'] = null;
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page->where('overtimePay.complete', false)->etc());
        $payload['finalize'] = true;
        $this->put(route('reports.update', $report), $payload)->assertSessionHasErrors(['hourly_rate' => 'Enter an hourly rate before finalizing.']);
        $this->post(route('reports.generate', $report))->assertSessionHasErrors('entries');
        $this->post(route('reports.generate-docx', $report))->assertSessionHasErrors('entries');
    }

    public function test_zero_rate_is_valid_and_results_in_zero_pay(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['hourly_rate'] = '0';
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $this->get(route('reports.show', AccomplishmentReport::firstOrFail()))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.complete', true)->where('overtimePay.net_cents', 0)->etc());
    }

    public function test_new_reports_default_to_time_when_quantity_type_is_not_submitted(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        unset($payload['quantity_mode']);
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $this->assertSame('time', $report->quantity_mode);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'quantity_mode' => 'time', 'time_minutes' => 60]);
        $this->assertSame('time', (new AccomplishmentReport)->quantity_mode);
    }

    public function test_report_rate_is_preserved_when_update_omits_it(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        unset($payload['hourly_rate']);
        $this->put(route('reports.update', $report), $payload)->assertSessionHasNoErrors();
        $this->assertSame('100.00', $report->fresh()->hourly_rate);
    }

    public function test_migration_carries_uniform_old_rates_and_leaves_mixed_rates_for_user_to_set(): void
    {
        $migration = require database_path('migrations/2026_09_30_023436_add_hourly_rate_to_accomplishment_reports_table.php');
        $migration->down();
        $user = User::factory()->create(['is_admin' => true]);
        $uniform = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026]);
        $mixed = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026]);
        foreach ([$uniform, $mixed] as $report) {
            $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity_mode' => 'time', 'hourly_rate' => '100.00', 'time_minutes' => 60]);
            $report->entries()->create(['accomplishment_date' => '2026-09-05', 'quantity_mode' => 'time', 'hourly_rate' => $report->is($uniform) ? '100.00' : '200.00', 'time_minutes' => 60]);
        }
        $migration->up();
        $this->assertDatabaseCount('accomplishment_entries', 4);
        $this->assertSame('100.00', $uniform->fresh()->hourly_rate);
        $this->assertNull($mixed->fresh()->hourly_rate);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time', 'hourly_rate' => '100.00',
            'prepared_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Prepared', 'position' => 'Programmer', 'signatory_type' => 'prepared_by'])->id,
            'certified_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Certified', 'position' => 'Officer', 'signatory_type' => 'certified_correct'])->id,
            'approved_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Approved', 'position' => 'Head', 'signatory_type' => 'approved'])->id,
            'entries' => [['accomplishment_date' => '2026-09-04', 'time_minutes' => 60, 'hourly_rate' => '100.00', 'task_accomplished' => 'Completed work']],
        ];
    }
}
