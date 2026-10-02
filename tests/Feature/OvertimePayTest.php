<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\Signatory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class OvertimePayTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_displays_hours_and_minutes_and_preserves_claimable_pay(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['entries'] = [
            ['accomplishment_date' => '2026-09-04', 'time_minutes' => 311, 'task_accomplished' => 'Friday work'],
            ['accomplishment_date' => '2026-09-05', 'time_minutes' => 60, 'task_accomplished' => 'Saturday work'],
            ['accomplishment_date' => '2026-09-06', 'time_minutes' => 11, 'task_accomplished' => 'Sunday work'],
        ];
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();

        $pdf = $this->post(route('reports.generate', $report))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $pdfText = preg_replace('/\s+/', ' ', (new Parser)->parseContent($pdf->getContent())->getText());
        $this->assertStringContainsString('5h 11m', $pdfText);
        $this->assertStringContainsString('1h', $pdfText);
        $this->assertStringContainsString('11m', $pdfText);
        $this->assertStringNotContainsString('5.1833 hours', $pdfText);
        $this->assertStringNotContainsString('HOURLY RATE', $pdfText);
        $this->assertStringNotContainsString('100.00', $pdfText);
        $this->assertStringNotContainsString('Gross OT pay:', $pdfText);
        $this->assertStringNotContainsString('Deduction (20%):', $pdfText);
        $this->assertStringNotContainsString('Net OT pay:', $pdfText);
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.gross_cents', 82542)
            ->where('overtimePay.deduction_cents', 16508)
            ->where('overtimePay.net_cents', 66034)->etc());
        $this->assertSame(311, $report->entries()->orderBy('sort_order')->firstOrFail()->time_minutes);
        $this->assertSame('generated', $report->fresh()->status);
    }

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
        $this->assertStringNotContainsString('HOURLY RATE', $pdfText);
        $this->assertStringNotContainsString('Net OT pay:', $pdfText);
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

    public function test_jo_uses_daily_rate_and_total_minutes_at_one_hundred_percent_for_every_day(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['is_jo'] = true;
        $payload['daily_rate'] = '800.00';
        $payload['entries'] = [
            ['accomplishment_date' => '2026-09-04', 'time_minutes' => 90, 'task_accomplished' => 'Friday work'],
            ['accomplishment_date' => '2026-09-05', 'time_minutes' => 135, 'task_accomplished' => 'Saturday work'],
        ];

        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $this->assertTrue($report->is_jo);
        $this->assertSame('800.00', $report->daily_rate);
        $this->assertSame('0.00', $report->jo_tax_percent);
        $this->get(route('reports.edit', $report))->assertInertia(fn (Assert $page) => $page
            ->where('report.is_jo', true)->where('report.daily_rate', '800.00')->etc());
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.weekday.total_minutes', 90)
            ->where('overtimePay.weekend.total_minutes', 135)
            ->where('overtimePay.gross_cents', 37500)
            ->where('overtimePay.deduction_cents', 0)
            ->where('overtimePay.net_cents', 37500)
            ->where('overtimePay.complete', true)->etc());
        $this->get(route('reports.preview', $report))->assertOk()->assertSee('DAILY RATE')->assertSee('JO pay')->assertDontSee('Deduction (20%)');
        $pdf = $this->post(route('reports.generate', $report))->assertOk();
        $this->assertStringContainsString('JO pay', (new Parser)->parseContent($pdf->getContent())->getText());
        $docx = $this->post(route('reports.generate-docx', $report))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'jo-pay-docx-');
        file_put_contents($path, $docx->getContent());
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('DAILY RATE', $xml);
            $this->assertStringContainsString('JO pay', $xml);
            $this->assertStringNotContainsString('Deduction (20%)', $xml);
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_jo_deducts_only_the_entered_tax_percentage_and_exports_it(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['is_jo'] = true;
        $payload['daily_rate'] = '800.00';
        $payload['jo_tax_percent'] = '7.50';
        $payload['entries'] = [
            ['accomplishment_date' => '2026-09-04', 'time_minutes' => 90, 'task_accomplished' => 'Friday work'],
            ['accomplishment_date' => '2026-09-05', 'time_minutes' => 135, 'task_accomplished' => 'Saturday work'],
        ];

        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $this->get(route('reports.edit', $report))->assertInertia(fn (Assert $page) => $page
            ->where('report.jo_tax_percent', '7.50')->etc());
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.gross_cents', 37500)
            ->where('overtimePay.deduction_cents', 2813)
            ->where('overtimePay.net_cents', 34687)->etc());
        $this->put(route('reports.daily-rate.update', $report), ['daily_rate' => '800.00', 'jo_tax_percent' => '10.00'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.deduction_cents', 3750)
            ->where('overtimePay.net_cents', 33750)->etc());
        $this->put(route('reports.daily-rate.update', $report), ['daily_rate' => '800.00', 'jo_tax_percent' => '7.50'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('reports.preview', $report))->assertOk()->assertSee('JO tax (7.50%)')->assertSee('28.13')->assertSee('346.87')->assertDontSee('Deduction (20%)');

        $docx = $this->post(route('reports.generate-docx', $report))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'jo-tax-docx-');
        file_put_contents($path, $docx->getContent());
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertStringContainsString('JO tax (7.50%)', $xml);
            $this->assertStringContainsString('28.13', $xml);
            $this->assertStringContainsString('346.87', $xml);
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_jo_requires_daily_rate_for_finalization_and_can_switch_back_to_regular_ot(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['is_jo'] = true;
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $this->post(route('reports.generate', $report))->assertSessionHasErrors('entries');
        $payload['finalize'] = true;
        $this->put(route('reports.update', $report), $payload)->assertSessionHasErrors('daily_rate');

        $payload['daily_rate'] = '800.00';
        $payload['entries'][0]['id'] = $report->entries()->firstOrFail()->id;
        $this->put(route('reports.update', $report), $payload)->assertSessionHasNoErrors();
        $this->put(route('reports.daily-rate.update', $report), ['daily_rate' => '400.00'])->assertSessionHasNoErrors();
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page->where('overtimePay.net_cents', 5000)->etc());

        $payload['is_jo'] = false;
        $this->put(route('reports.update', $report), $payload)->assertSessionHasNoErrors();
        $this->assertFalse($report->fresh()->is_jo);
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('overtimePay.gross_cents', 12500)->where('overtimePay.net_cents', 10000)->etc());
    }

    #[TestWith(['-1'])]
    #[TestWith(['1.001'])]
    #[TestWith(['abc'])]
    #[TestWith(['100000000'])]
    public function test_invalid_jo_daily_rates_are_rejected(string $rate): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['is_jo'] = true;
        $payload['daily_rate'] = $rate;

        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasErrors('daily_rate');
        $this->assertDatabaseCount('accomplishment_reports', 0);
    }

    #[TestWith(['-1'])]
    #[TestWith(['100.01'])]
    #[TestWith(['1.001'])]
    #[TestWith(['abc'])]
    public function test_invalid_jo_tax_percentages_are_rejected(string $percent): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['is_jo'] = true;
        $payload['daily_rate'] = '800.00';
        $payload['jo_tax_percent'] = $percent;

        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasErrors('jo_tax_percent');
        $this->assertDatabaseCount('accomplishment_reports', 0);
    }

    public function test_invalid_jo_tax_update_does_not_change_saved_settings(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['is_jo'] = true;
        $payload['daily_rate'] = '800.00';
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();

        $this->put(route('reports.daily-rate.update', $report), ['daily_rate' => '400.00', 'jo_tax_percent' => '100.01'])->assertSessionHasErrors('jo_tax_percent');
        $this->assertSame('800.00', $report->fresh()->daily_rate);
        $this->assertSame('0.00', $report->fresh()->jo_tax_percent);
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

    public function test_missing_rate_allows_pdf_but_still_requires_rate_for_finalization_and_docx(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $payload = $this->payload($user);
        $payload['hourly_rate'] = null;
        $this->actingAs($user)->post(route('reports.store'), $payload)->assertSessionHasNoErrors();
        $report = AccomplishmentReport::firstOrFail();
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page->where('overtimePay.complete', false)->etc());
        $payload['finalize'] = true;
        $this->put(route('reports.update', $report), $payload)->assertSessionHasErrors(['hourly_rate' => 'Enter an hourly rate before finalizing.']);
        $pdf = $this->post(route('reports.generate', $report))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = (new Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringContainsString('1h', $text);
        $this->assertStringNotContainsString('Enter a hourly rate', $text);
        $this->assertSame('generated', $report->fresh()->status);
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

    public function test_existing_jo_monthly_rate_is_converted_to_daily_rate(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026, 'is_jo' => true]);
        $migration = require database_path('migrations/2026_09_30_104725_convert_jo_monthly_rate_to_daily_rate.php');

        $migration->down();
        DB::table('accomplishment_reports')->where('id', $report->id)->update(['monthly_rate' => '17600.00']);
        $migration->up();

        $this->assertSame('800.00', $report->fresh()->daily_rate);
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
