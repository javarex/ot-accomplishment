<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\DtrImport;
use App\Models\Signatory;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DtrImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_previewing_dtr_returns_all_attendance_rows_without_saving_report_or_upload(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p><p>5 08:00 12:00 13:00 17:00</p>');
        $pdf->render();

        $this->actingAs($user)->withHeaders(['Accept' => 'application/json'])->post(route('reports.dtr.preview'), [
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
        ])->assertOk()->assertJsonPath('month', 10)->assertJsonPath('year', 2026)
            ->assertJsonPath('entries.0.date', '2026-10-04')
            ->assertJsonPath('entries.0.overtime_minutes', 135)
            ->assertJsonPath('entries.1.date', '2026-10-05')
            ->assertJsonPath('entries.1.overtime_minutes', null)
            ->assertJsonPath('entries.1.am_in', '08:00')
            ->assertJsonPath('entries.1.pm_out', '17:00')
            ->assertJsonCount(2, 'entries');

        $this->assertDatabaseCount('accomplishment_reports', 0);
        $this->assertDatabaseCount('dtr_imports', 0);
        $this->assertDatabaseCount('dtr_entries', 0);
        $this->assertSame([], Storage::disk('local')->files('dtr-imports'));
    }

    public function test_previewing_dtr_without_overtime_returns_attendance_records(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>5 08:00 12:00 13:00 17:00</p><p>6 LEAVE</p>');
        $pdf->render();

        $this->actingAs($user)->withHeaders(['Accept' => 'application/json'])->post(route('reports.dtr.preview'), [
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
        ])->assertOk()->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.0.overtime_minutes', null)
            ->assertJsonPath('entries.1.date', '2026-10-06')
            ->assertJsonPath('entries.1.remarks', 'LEAVE');

        $this->assertDatabaseCount('accomplishment_reports', 0);
        $this->assertDatabaseCount('dtr_imports', 0);
    }

    public function test_invalid_dtr_preview_leaves_no_report_or_import(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>No month or attendance rows</p>');
        $pdf->render();

        $this->actingAs($user)->withHeaders(['Accept' => 'application/json'])->post(route('reports.dtr.preview'), [
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
        ])->assertUnprocessable()->assertJsonValidationErrors('dtr');

        $this->assertDatabaseCount('accomplishment_reports', 0);
        $this->assertDatabaseCount('dtr_imports', 0);
        $this->assertSame([], Storage::disk('local')->files('dtr-imports'));
    }

    public function test_saving_after_preview_creates_report_and_keeps_the_dtr_source(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p>');
        $pdf->render();

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 10, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [['accomplishment_date' => '2026-10-04', 'time_minutes' => 135, 'task_accomplished' => 'Completed overtime work']],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
            'dtr_previewed' => true,
            'dtr_import_dates' => ['2026-10-04'],
        ]);

        $report = AccomplishmentReport::firstOrFail();
        $import = DtrImport::firstOrFail();
        $dtrEntry = $import->entries()->firstOrFail();
        $response->assertRedirect(route('reports.edit', $report))->assertSessionHasNoErrors();
        $this->assertSame('imported', $import->import_status);
        $this->assertTrue($dtrEntry->selected_for_import);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'dtr_entry_id' => $dtrEntry->id, 'time_minutes' => 135, 'task_accomplished' => 'Completed overtime work']);
        Storage::disk('local')->assertExists($import->file_path);
    }

    public function test_saving_manual_ot_for_an_ordinary_dtr_date_keeps_the_time_and_source(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 08:00 12:00 13:00 17:00</p>');
        $pdf->render();

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 10, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [['accomplishment_date' => '2026-10-04', 'time_minutes' => 135, 'task_accomplished' => 'Completed overtime work']],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
            'dtr_previewed' => true,
            'dtr_import_dates' => ['2026-10-04'],
            'dtr_rows' => [['date' => '2026-10-04', 'am_in' => '08:15', 'am_out' => null, 'pm_in' => '13:10', 'pm_out' => '19:15', 'overtime_minutes' => 135, 'remarks' => 'Corrected attendance']],
        ]);

        $report = AccomplishmentReport::firstOrFail();
        $import = DtrImport::firstOrFail();
        $dtrEntry = $import->entries()->firstOrFail();
        $response->assertRedirect(route('reports.edit', $report))->assertSessionHasNoErrors();
        $this->assertSame('imported', $import->import_status);
        $this->assertTrue($dtrEntry->selected_for_import);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'dtr_entry_id' => $dtrEntry->id, 'time_minutes' => 135, 'task_accomplished' => 'Completed overtime work']);
        $this->assertDatabaseHas('dtr_entries', ['id' => $dtrEntry->id, 'am_in' => '08:15', 'am_out' => null, 'pm_in' => '13:10', 'pm_out' => '19:15', 'overtime_minutes' => 135, 'remarks' => 'Corrected attendance']);
        $this->assertSame('08:00', $import->parsed_data['entries'][0]['am_in']);
        Storage::disk('local')->assertExists($import->file_path);
    }

    public function test_edited_dtr_date_missing_from_upload_does_not_save_a_report(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 08:00 12:00 13:00 17:00</p>');
        $pdf->render();

        $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 10, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [['accomplishment_date' => '2026-10-04', 'time_minutes' => 135]],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
            'dtr_previewed' => true,
            'dtr_rows' => [['date' => '2026-10-05', 'am_in' => '08:00']],
        ])->assertSessionHasErrors('dtr_rows');

        $this->assertDatabaseCount('accomplishment_reports', 0);
        $this->assertDatabaseCount('dtr_imports', 0);
        $this->assertSame([], Storage::disk('local')->files('dtr-imports'));
    }

    public function test_invalid_edited_attendance_time_does_not_save_a_report(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 08:00 12:00 13:00 17:00</p>');
        $pdf->render();

        $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 10, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [['accomplishment_date' => '2026-10-04', 'time_minutes' => 135]],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
            'dtr_previewed' => true,
            'dtr_rows' => [['date' => '2026-10-04', 'am_in' => '25:00']],
        ])->assertSessionHasErrors('dtr_rows.0.am_in');

        $this->assertDatabaseCount('accomplishment_reports', 0);
        $this->assertDatabaseCount('dtr_imports', 0);
    }

    public function test_invalid_preview_selection_does_not_save_a_report_or_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p>');
        $pdf->render();

        $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 10, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [['accomplishment_date' => '2026-10-04', 'time_minutes' => 135]],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
            'dtr_previewed' => true,
            'dtr_import_dates' => ['2026-10-05'],
        ])->assertSessionHasErrors('dtr_import_dates');

        $this->assertDatabaseCount('accomplishment_reports', 0);
        $this->assertDatabaseCount('dtr_imports', 0);
        $this->assertSame([], Storage::disk('local')->files('dtr-imports'));
    }

    public function test_report_can_be_created_with_dtr_upload_and_opens_review(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>September 2026</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p>');
        $pdf->render();

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
        ]);

        $report = AccomplishmentReport::firstOrFail();
        $import = DtrImport::firstOrFail();
        $response->assertRedirect(route('reports.dtr.show', [$report, $import]));
        $this->assertSame($report->id, $import->accomplishment_report_id);
        $this->assertSame('draft', $report->status);
        $this->assertSame('time', $report->quantity_mode);
        $this->assertDatabaseHas('dtr_entries', ['dtr_import_id' => $import->id, 'work_date' => '2026-09-04', 'overtime_minutes' => 135]);
        $this->assertDatabaseCount('accomplishment_entries', 0);
        Storage::disk('local')->assertExists($import->file_path);
    }

    public function test_new_report_uses_dtr_period_instead_of_create_forms_default_period(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p>');
        $pdf->render();

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
        ]);

        $report = AccomplishmentReport::firstOrFail();
        $import = DtrImport::firstOrFail();
        $response->assertRedirect(route('reports.dtr.show', [$report, $import]))->assertSessionHasNoErrors();
        $this->assertSame(10, $report->report_month);
        $this->assertSame(2026, $report->report_year);
        $this->assertSame(10, $import->month);
        $this->assertDatabaseHas('dtr_entries', ['dtr_import_id' => $import->id, 'work_date' => '2026-10-04']);
    }

    public function test_unreadable_dtr_period_does_not_leave_a_draft(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p>');
        $pdf->render();

        $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time',
            ...$this->signatories($user),
            'entries' => [],
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
        ])->assertSessionHasErrors('dtr');

        $this->assertDatabaseCount('accomplishment_reports', 0);
        $this->assertDatabaseCount('dtr_imports', 0);
        $this->assertSame([], Storage::disk('local')->files('dtr-imports'));
    }

    public function test_existing_report_still_rejects_dtr_from_another_period(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time']);
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>October 2026</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p>');
        $pdf->render();

        $this->actingAs($user)->post(route('reports.dtr.store', $report), [
            'dtr' => UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output()),
        ])->assertSessionHasErrors('dtr');

        $this->assertDatabaseCount('dtr_imports', 0);
        $this->assertSame(9, $report->fresh()->report_month);
    }

    public function test_pdf_upload_stages_detected_overtime_for_review_without_creating_accomplishments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Name: RAYMART N. ITANONG</p><p>September 2026</p><p>4 07:44 12:01 12:53 07:15 OT 2h 15m</p>');
        $pdf->render();
        $file = UploadedFile::fake()->createWithContent('dtr.pdf', $pdf->output());

        $response = $this->actingAs($user)->post(route('reports.dtr.store', $report), ['dtr' => $file]);

        $import = DtrImport::firstOrFail();
        $response->assertRedirect(route('reports.dtr.show', [$report, $import]));
        $this->assertDatabaseHas('dtr_entries', ['dtr_import_id' => $import->id, 'work_date' => '2026-09-04', 'overtime_minutes' => 135]);
        $this->assertDatabaseCount('accomplishment_entries', 0);
    }

    public function test_review_page_sends_attendance_rows_without_raw_parsed_pdf_data(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);
        $import = DtrImport::create([
            'user_id' => $user->id,
            'accomplishment_report_id' => $report->id,
            'employee_name' => 'Raymart N. Itanong',
            'month' => 9,
            'year' => 2026,
            'original_filename' => 'dtr.pdf',
            'file_path' => 'dtr-imports/test.pdf',
            'file_hash' => str_repeat('d', 64),
            'parsed_data' => ['raw' => str_repeat('sample', 100)],
        ]);
        $entry = $import->entries()->create(['work_date' => '2026-09-04', 'overtime_minutes' => 135]);

        $this->actingAs($user)->get(route('reports.dtr.show', [$report, $import]))->assertInertia(fn (Assert $page) => $page
            ->component('dtr/review')
            ->where('import.entries.0.id', $entry->id)
            ->where('import.entries.0.overtime_minutes', 135)
            ->missing('import.parsed_data')
            ->missing('import.file_path')
            ->etc());
    }

    public function test_reviewed_overtime_import_skips_existing_date_by_default(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);
        $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '2 documents', 'task_accomplished' => 'Existing task']);
        $import = DtrImport::create(['user_id' => $user->id, 'accomplishment_report_id' => $report->id, 'employee_name' => 'Raymart N. Itanong', 'month' => 9, 'year' => 2026, 'original_filename' => 'dtr.pdf', 'file_path' => 'dtr-imports/test.pdf', 'file_hash' => str_repeat('a', 64)]);
        $first = $import->entries()->create(['work_date' => '2026-09-04', 'overtime_minutes' => 135]);
        $second = $import->entries()->create(['work_date' => '2026-09-05', 'overtime_minutes' => 180]);

        $response = $this->actingAs($user)->post(route('reports.dtr.commit', [$report, $import]), ['entry_ids' => [$first->id, $second->id], 'duplicate_action' => 'skip']);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-04', 'quantity' => '2 documents', 'task_accomplished' => 'Existing task']);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-05', 'quantity' => '3h', 'task_accomplished' => null]);
        $this->assertDatabaseCount('accomplishment_entries', 2);
    }

    public function test_time_based_import_uses_dtr_minutes_for_weekday_and_weekend_quantities(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time']);
        $import = DtrImport::create(['user_id' => $user->id, 'accomplishment_report_id' => $report->id, 'employee_name' => 'Raymart N. Itanong', 'month' => 9, 'year' => 2026, 'original_filename' => 'dtr.pdf', 'file_path' => 'dtr-imports/test.pdf', 'file_hash' => str_repeat('e', 64)]);
        $weekday = $import->entries()->create(['work_date' => '2026-09-04', 'overtime_minutes' => 130]);
        $weekend = $import->entries()->create(['work_date' => '2026-09-05', 'overtime_minutes' => 130]);

        $response = $this->actingAs($user)->post(route('reports.dtr.commit', [$report, $import]), ['entry_ids' => [$weekday->id, $weekend->id], 'duplicate_action' => 'skip']);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-04', 'quantity_mode' => 'time', 'time_minutes' => 130, 'quantity' => '2.1667 hours']);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-05', 'quantity_mode' => 'time', 'time_minutes' => 130, 'quantity' => '2.1667 hours']);
    }

    public function test_time_based_import_requires_manual_quantity_to_be_time(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time']);
        $import = DtrImport::create(['user_id' => $user->id, 'accomplishment_report_id' => $report->id, 'employee_name' => 'Raymart N. Itanong', 'month' => 9, 'year' => 2026, 'original_filename' => 'dtr.pdf', 'file_path' => 'dtr-imports/test.pdf', 'file_hash' => str_repeat('f', 64)]);
        $ordinaryDay = $import->entries()->create(['work_date' => '2026-09-05']);

        $response = $this->actingAs($user)->post(route('reports.dtr.commit', [$report, $import]), [
            'entry_ids' => [$ordinaryDay->id], 'duplicate_action' => 'skip', 'manual_quantities' => [$ordinaryDay->id => '3 documents'],
        ]);

        $response->assertSessionHasErrors('manual_quantities.'.$ordinaryDay->id);
        $this->assertDatabaseCount('accomplishment_entries', 0);
    }

    public function test_time_based_import_accepts_manual_hours_and_minutes_for_ordinary_day(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time']);
        $import = DtrImport::create(['user_id' => $user->id, 'accomplishment_report_id' => $report->id, 'employee_name' => 'Raymart N. Itanong', 'month' => 9, 'year' => 2026, 'original_filename' => 'dtr.pdf', 'file_path' => 'dtr-imports/test.pdf', 'file_hash' => str_repeat('g', 64)]);
        $ordinaryDay = $import->entries()->create(['work_date' => '2026-09-05']);

        $response = $this->actingAs($user)->post(route('reports.dtr.commit', [$report, $import]), [
            'entry_ids' => [$ordinaryDay->id], 'duplicate_action' => 'skip', 'manual_quantities' => [$ordinaryDay->id => '2h 10m'],
        ]);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-05', 'quantity_mode' => 'time', 'time_minutes' => 130, 'quantity' => '2.1667 hours']);
    }

    public function test_selected_ordinary_day_requires_manual_quantity(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);
        $import = DtrImport::create(['user_id' => $user->id, 'accomplishment_report_id' => $report->id, 'employee_name' => 'Raymart N. Itanong', 'month' => 9, 'year' => 2026, 'original_filename' => 'dtr.pdf', 'file_path' => 'dtr-imports/test.pdf', 'file_hash' => str_repeat('b', 64)]);
        $ordinaryDay = $import->entries()->create(['work_date' => '2026-09-05']);

        $response = $this->actingAs($user)->post(route('reports.dtr.commit', [$report, $import]), ['entry_ids' => [$ordinaryDay->id], 'duplicate_action' => 'skip']);

        $response->assertSessionHasErrors('manual_quantities.'.$ordinaryDay->id);
        $this->assertDatabaseCount('accomplishment_entries', 0);
    }

    public function test_selected_ordinary_day_accepts_free_text_quantity(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);
        $import = DtrImport::create(['user_id' => $user->id, 'accomplishment_report_id' => $report->id, 'employee_name' => 'Raymart N. Itanong', 'month' => 9, 'year' => 2026, 'original_filename' => 'dtr.pdf', 'file_path' => 'dtr-imports/test.pdf', 'file_hash' => str_repeat('c', 64)]);
        $ordinaryDay = $import->entries()->create(['work_date' => '2026-09-05']);

        $response = $this->actingAs($user)->post(route('reports.dtr.commit', [$report, $import]), [
            'entry_ids' => [$ordinaryDay->id],
            'duplicate_action' => 'skip',
            'manual_quantities' => [$ordinaryDay->id => '3 documents'],
        ]);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-05', 'quantity' => '3 documents']);
    }

    /** @return array<string, int> */
    private function signatories(User $user): array
    {
        return [
            'prepared_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Prepared', 'position' => 'Officer', 'signatory_type' => 'prepared_by'])->id,
            'certified_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Certified', 'position' => 'Officer', 'signatory_type' => 'certified_correct'])->id,
            'approved_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Approved', 'position' => 'Officer', 'signatory_type' => 'approved'])->id,
        ];
    }
}
