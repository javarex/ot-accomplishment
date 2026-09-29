<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\DtrImport;
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

    public function test_pdf_upload_stages_detected_overtime_for_review_without_creating_accomplishments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026]);
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
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026]);
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
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026]);
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

    public function test_selected_ordinary_day_requires_manual_quantity(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026]);
        $import = DtrImport::create(['user_id' => $user->id, 'accomplishment_report_id' => $report->id, 'employee_name' => 'Raymart N. Itanong', 'month' => 9, 'year' => 2026, 'original_filename' => 'dtr.pdf', 'file_path' => 'dtr-imports/test.pdf', 'file_hash' => str_repeat('b', 64)]);
        $ordinaryDay = $import->entries()->create(['work_date' => '2026-09-05']);

        $response = $this->actingAs($user)->post(route('reports.dtr.commit', [$report, $import]), ['entry_ids' => [$ordinaryDay->id], 'duplicate_action' => 'skip']);

        $response->assertSessionHasErrors('manual_quantities.'.$ordinaryDay->id);
        $this->assertDatabaseCount('accomplishment_entries', 0);
    }

    public function test_selected_ordinary_day_accepts_free_text_quantity(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026]);
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
}
