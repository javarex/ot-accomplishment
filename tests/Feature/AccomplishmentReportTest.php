<?php

namespace Tests\Feature;

use App\Contracts\AccomplishmentAiService;
use App\Models\AccomplishmentReport;
use App\Models\Signatory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class AccomplishmentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_table_includes_preparer_details_and_keeps_the_owner(): void
    {
        $owner = User::factory()->create();
        $signatory = Signatory::create(['user_id' => $owner->id, 'name' => 'Linked Preparer',
            'position' => 'Staff', 'signatory_type' => 'prepared_by']);
        AccomplishmentReport::create(['user_id' => $owner->id, 'report_month' => 9,
            'report_year' => 2026, 'prepared_name' => 'Report Preparer', 'prepared_by_id' => $signatory->id]);

        $this->actingAs($owner)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('reports.data.0.user_id', $owner->id)
            ->where('reports.data.0.user.name', $owner->name)
            ->where('reports.data.0.prepared_name', 'Report Preparer')
            ->where('reports.data.0.prepared_by.name', 'Linked Preparer')->etc());
    }

    public function test_user_can_save_draft_with_free_text_quantity_and_blank_task(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9,
            'quantity_mode' => 'custom', 'report_year' => 2026,
            ...$signatories,
            'entries' => [['accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => '']],
        ]);

        $report = AccomplishmentReport::firstOrFail();
        $response->assertRedirect(route('reports.edit', $report));
        $this->assertSame('draft', $report->status);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-04', 'quantity_mode' => 'custom', 'time_minutes' => null, 'quantity' => '3 documents']);
    }

    public function test_time_quantity_preserves_actual_weekday_and_weekend_hours_and_ignores_submitted_result(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time', ...$signatories,
            'entries' => [
                ['accomplishment_date' => '2026-09-04', 'quantity_mode' => 'custom', 'time_minutes' => 135, 'quantity' => 'tampered', 'task_accomplished' => 'Friday task'],
                ['accomplishment_date' => '2026-09-05', 'time_minutes' => 135, 'task_accomplished' => 'Saturday task'],
            ],
        ]);

        $report = AccomplishmentReport::firstOrFail();
        $response->assertRedirect(route('reports.edit', $report));
        $this->assertSame('time', $report->quantity_mode);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-04', 'quantity_mode' => 'time', 'time_minutes' => 135, 'quantity' => '2.25 hours']);
        $this->assertDatabaseHas('accomplishment_entries', ['accomplishment_report_id' => $report->id, 'accomplishment_date' => '2026-09-05', 'quantity_mode' => 'time', 'time_minutes' => 135, 'quantity' => '2.25 hours']);
        $this->get(route('reports.edit', $report))->assertInertia(fn (Assert $page) => $page
            ->component('reports/editor')
            ->where('report.quantity_mode', 'time')
            ->where('report.entries.0.quantity_mode', 'time')
            ->where('report.entries.0.time_minutes', 135)
            ->etc());
    }

    public function test_time_quantity_preserves_actual_hours_when_date_changes(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time', ...$signatories]);
        $entry = $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity_mode' => 'time', 'time_minutes' => 60, 'quantity' => '1.25 hours']);

        $response = $this->actingAs($user)->put(route('reports.update', $report), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time', ...$signatories,
            'entries' => [['id' => $entry->id, 'accomplishment_date' => '2026-09-06', 'time_minutes' => 60, 'quantity' => '1.25 hours']],
        ]);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['id' => $entry->id, 'accomplishment_date' => '2026-09-06', 'quantity' => '1 hours']);
    }

    public function test_switching_to_custom_quantity_preserves_text_and_clears_time(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time', ...$signatories]);
        $entry = $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity_mode' => 'time', 'time_minutes' => 60, 'quantity' => '1.25 hours']);

        $response = $this->actingAs($user)->put(route('reports.update', $report), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'custom', ...$signatories,
            'entries' => [['id' => $entry->id, 'accomplishment_date' => '2026-09-04', 'time_minutes' => 60, 'quantity' => '3 documents']],
        ]);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['id' => $entry->id, 'quantity_mode' => 'custom', 'time_minutes' => null, 'quantity' => '3 documents']);
    }

    public function test_time_quantity_requires_positive_minutes(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time', ...$signatories,
            'entries' => [['accomplishment_date' => '2026-09-04', 'time_minutes' => 0]],
        ]);

        $response->assertSessionHasErrors('entries.0.time_minutes');
        $this->assertDatabaseCount('accomplishment_reports', 0);
    }

    public function test_switching_an_imported_time_text_to_time_mode_uses_its_hours_and_minutes(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories]);
        $entry = $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '2h 10m']);

        $response = $this->actingAs($user)->put(route('reports.update', $report), [
            'report_month' => 9, 'report_year' => 2026, 'quantity_mode' => 'time', ...$signatories,
            'entries' => [['id' => $entry->id, 'accomplishment_date' => '2026-09-04', 'quantity' => '2h 10m']],
        ]);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['id' => $entry->id, 'quantity_mode' => 'time', 'time_minutes' => 130, 'quantity' => '2.1667 hours']);
    }

    public function test_reordered_entries_keep_their_dates_quantities_and_tasks(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories]);
        $first = $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'First task', 'sort_order' => 0]);
        $second = $report->entries()->create(['accomplishment_date' => '2026-09-05', 'quantity' => '1 module', 'task_accomplished' => 'Second task', 'sort_order' => 1]);

        $response = $this->actingAs($user)->put(route('reports.update', $report), [
            'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories,
            'entries' => [
                ['id' => $second->id, 'accomplishment_date' => '2026-09-05', 'quantity' => '1 module', 'task_accomplished' => 'Second task'],
                ['id' => $first->id, 'accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'First task'],
            ],
        ]);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertSame([$second->id, $first->id], $report->fresh()->entries->pluck('id')->all());
        $this->assertSame('2026-09-05', $second->fresh()->accomplishment_date->toDateString());
        $this->assertSame('1 module', $second->fresh()->quantity);
        $this->assertSame('Second task', $second->fresh()->task_accomplished);
    }

    public function test_swapping_tasks_between_dates_keeps_each_dates_quantity(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories]);
        $first = $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'First task', 'sort_order' => 0]);
        $second = $report->entries()->create(['accomplishment_date' => '2026-09-05', 'quantity' => '1 module', 'task_accomplished' => 'Second task', 'sort_order' => 1]);

        $response = $this->actingAs($user)->put(route('reports.update', $report), [
            'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories,
            'entries' => [
                ['id' => $first->id, 'accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'Second task'],
                ['id' => $second->id, 'accomplishment_date' => '2026-09-05', 'quantity' => '1 module', 'task_accomplished' => 'First task'],
            ],
        ]);

        $response->assertRedirect(route('reports.edit', $report));
        $this->assertDatabaseHas('accomplishment_entries', ['id' => $first->id, 'accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'Second task']);
        $this->assertDatabaseHas('accomplishment_entries', ['id' => $second->id, 'accomplishment_date' => '2026-09-05', 'quantity' => '1 module', 'task_accomplished' => 'First task']);
    }

    public function test_finalizing_with_blank_task_is_rejected(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9,
            'quantity_mode' => 'custom', 'report_year' => 2026,
            ...$signatories,
            'finalize' => true,
            'entries' => [['accomplishment_date' => '2026-09-04', 'quantity' => '2h 15m', 'task_accomplished' => '']],
        ]);

        $response->assertSessionHasErrors('entries.0.task_accomplished');
        $this->assertDatabaseCount('accomplishment_reports', 0);
    }

    public function test_other_user_can_view_but_cannot_edit_report(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $owner->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);

        $this->actingAs($other)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('reports.data.0.id', $report->id)
            ->where('currentUserId', $other->id)
            ->etc());
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->component('reports/show')
            ->where('canEdit', false)
            ->where('report.id', $report->id)
            ->etc());
        $this->get(route('reports.preview', $report))->assertOk()->assertDontSee('Export DOCX');
        $this->get(route('reports.edit', $report))->assertForbidden();
        $this->put(route('reports.update', $report), [])->assertForbidden();
        $this->delete(route('reports.destroy', $report))->assertForbidden();
        $this->post(route('reports.generate', $report))->assertForbidden();
        $this->post(route('reports.generate-docx', $report))->assertForbidden();
        $this->post(route('reports.ai.improve', $report), ['text' => 'a task'])->assertForbidden();
        $this->post(route('reports.dtr.store', $report))->assertForbidden();
        $this->assertDatabaseHas('accomplishment_reports', ['id' => $report->id, 'user_id' => $owner->id]);
    }

    public function test_report_options_include_all_registered_signatories_in_their_sections(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherSignatories = $this->signatories($other);

        $this->actingAs($user)->get(route('reports.create'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/editor')
            ->where('signatories.prepared_by.0.id', $otherSignatories['prepared_by_id'])
            ->where('signatories.certified_correct.0.id', $otherSignatories['certified_by_id'])
            ->where('signatories.approved.0.id', $otherSignatories['approved_by_id'])
            ->etc());

        $response = $this->post(route('reports.store'), [
            'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$otherSignatories,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('accomplishment_reports', ['user_id' => $user->id, ...$otherSignatories]);
    }

    public function test_signatory_management_is_private_to_the_creator(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $signatory = Signatory::create(['user_id' => $owner->id, 'name' => 'Shared Name', 'position' => 'Officer', 'signatory_type' => 'prepared_by']);

        $this->actingAs($other)->get(route('signatories.index'))->assertInertia(fn (Assert $page) => $page
            ->component('signatories/index')
            ->where('signatories', [])
            ->etc());
        $this->put(route('signatories.update', $signatory), ['name' => 'Changed', 'position' => 'Officer', 'signatory_type' => 'prepared_by'])->assertForbidden();
        $this->delete(route('signatories.destroy', $signatory))->assertForbidden();
        $this->assertDatabaseHas('signatories', ['id' => $signatory->id, 'name' => 'Shared Name', 'is_active' => true]);
    }

    public function test_new_signatory_added_from_report_form_appears_in_its_section(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from(route('reports.create'))->post(route('signatories.store'), [
            'name' => 'New Approver',
            'position' => 'Department Head',
            'signatory_type' => 'approved',
        ]);

        $signatory = Signatory::firstOrFail();
        $response->assertRedirect(route('reports.create'))
            ->assertSessionHas('createdSignatory', ['id' => $signatory->id, 'type' => 'approved']);
        $this->get(route('reports.create'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/editor')
            ->where('signatories.approved.0.id', $signatory->id)
            ->where('signatories.approved.0.name', 'New Approver')
            ->etc());
    }

    public function test_admin_can_edit_another_users_report_without_changing_ownership(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $report = AccomplishmentReport::create(['user_id' => $owner->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);

        $this->actingAs($admin)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->where('reports.data.0.can_edit', true)->etc());
        $this->get(route('reports.show', $report))->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', true)->etc());
        $this->get(route('reports.edit', $report))->assertOk();
        $this->put(route('reports.update', $report), [
            'report_month' => 10, 'report_year' => 2026, 'quantity_mode' => 'custom',
            ...$this->signatories($owner), 'entries' => [], 'user_id' => $admin->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(10, $report->fresh()->report_month);
        $this->assertSame($owner->id, $report->fresh()->user_id);
    }

    public function test_finalizing_with_removed_last_entry_is_rejected(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories]);
        $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'Created the item library.']);

        $response = $this->actingAs($user)->put(route('reports.update', $report), [
            'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories, 'entries' => [], 'finalize' => true,
        ]);

        $response->assertSessionHasErrors('entries');
        $this->assertSame('draft', $report->fresh()->status);
        $this->assertDatabaseCount('accomplishment_entries', 1);
    }

    public function test_ai_suggestion_does_not_change_saved_task_until_user_accepts_it(): void
    {
        $user = User::factory()->create();
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026]);
        $entry = $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'fixed issue']);
        $this->mock(AccomplishmentAiService::class)->shouldReceive('improve')->once()->with('fixed issue')->andReturn('Fixed the reported issue.');

        $response = $this->actingAs($user)->post(route('reports.ai.improve', $report), ['text' => 'fixed issue']);

        $response->assertRedirect();
        $response->assertSessionHas('aiSuggestion.suggestion', 'Fixed the reported issue.');
        $this->assertSame('fixed issue', $entry->fresh()->task_accomplished);
    }

    public function test_approved_ai_wording_keeps_original_and_suggestion_for_audit(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);

        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories,
            'entries' => [[
                'accomplishment_date' => '2026-09-04', 'quantity' => '2h',
                'task_accomplished' => 'Fixed the user module issue.',
                'original_task_accomplished' => 'fixed error in user module',
                'ai_suggested_task_accomplished' => 'Fixed the user module issue.',
                'ai_enhanced' => true,
            ]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('accomplishment_entries', [
            'task_accomplished' => 'Fixed the user module issue.',
            'original_task_accomplished' => 'fixed error in user module',
            'ai_suggested_task_accomplished' => 'Fixed the user module issue.',
            'ai_enhanced' => true,
        ]);
    }

    public function test_complete_report_generates_pdf_and_updates_status(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 16:30:00'));
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories]);
        $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'Created the item library.']);

        $response = $this->actingAs($user)->post(route('reports.generate', $report));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="OT-accoplishment-2026-10-04_16-30-00.pdf"');
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
        $pdfText = (new Parser)->parseContent($response->getContent())->getText();
        $this->assertStringContainsString('OVERTIME ACCOMPLISHMENT REPORT', $pdfText);
        $this->assertStringContainsString('Created the item library.', $pdfText);
        $this->assertStringContainsString('3 documents', $pdfText);
        $this->assertStringNotContainsString('Total Rendered Overtime', $pdfText);
        $this->assertSame('generated', $report->fresh()->status);
    }

    public function test_complete_report_exports_docx_with_table_logos_and_footer(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories]);
        $report->entries()->create(['accomplishment_date' => '2026-09-04', 'quantity' => '3 documents', 'task_accomplished' => 'Created the item library.', 'sort_order' => 0]);
        $report->entries()->create(['accomplishment_date' => '2026-09-05', 'quantity' => '1 module', 'task_accomplished' => 'Prepared a & b records.', 'sort_order' => 1]);

        $response = $this->actingAs($user)->post(route('reports.generate-docx', $report));

        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('.docx', $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($path, $response->getContent());
        try {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path) === true);
            $document = $archive->getFromName('word/document.xml');
            $header = $archive->getFromName('word/header1.xml');
            $footer = $archive->getFromName('word/footer1.xml');
            $this->assertNotFalse($document);
            $this->assertNotFalse($header);
            $this->assertNotFalse($footer);
            $this->assertNotFalse($archive->getFromName('word/media/left.png'));
            $this->assertNotFalse($archive->getFromName('word/media/right.png'));
            $this->assertNotFalse($archive->getFromName('word/media/footer.jpeg'));
            $this->assertNotFalse(simplexml_load_string($document));
            $this->assertNotFalse(simplexml_load_string($header));
            $this->assertNotFalse(simplexml_load_string($footer));
            $this->assertStringContainsString('OVERTIME ACCOMPLISHMENT REPORT', $document);
            $this->assertStringContainsString('Times New Roman', $document);
            $this->assertStringContainsString('<w:insideH w:val="nil"/>', $document);
            $this->assertStringContainsString('<w:insideV w:val="single" w:sz="4"/>', $document);
            $this->assertStringNotContainsString('<w:shd', $document);
            $this->assertStringContainsString('Province of Davao de Oro', $header);
            $this->assertStringContainsString('3 documents', $document);
            $this->assertStringContainsString('1 module', $document);
            $this->assertStringNotContainsString('Total Rendered Overtime', $document);
            $this->assertStringContainsString('Prepared a &amp; b records.', $document);
            $this->assertLessThan(strpos($document, 'Prepared a &amp; b records.'), strpos($document, 'Created the item library.'));
            $this->assertStringContainsString('Page ', $footer);
            $this->assertStringContainsString('PICTO@davaodeoro.gov.ph', $footer);
            $archive->close();
        } finally {
            unlink($path);
        }
        $this->assertSame('generated', $report->fresh()->status);
    }

    public function test_long_report_continues_on_next_pdf_page_without_losing_entries(): void
    {
        $user = User::factory()->create();
        $signatories = $this->signatories($user);
        $report = AccomplishmentReport::create(['user_id' => $user->id, 'report_month' => 9, 'quantity_mode' => 'custom', 'report_year' => 2026, ...$signatories]);
        for ($day = 1; $day <= 25; $day++) {
            $report->entries()->create([
                'accomplishment_date' => sprintf('2026-09-%02d', $day),
                'quantity' => '2 documents',
                'task_accomplished' => "Completed documented accomplishment number {$day} and prepared its supporting records for review.",
                'sort_order' => $day,
            ]);
        }

        $response = $this->actingAs($user)->post(route('reports.generate', $report));

        $pdf = (new Parser)->parseContent($response->getContent());
        $this->assertGreaterThan(1, count($pdf->getPages()));
        foreach ($pdf->getPages() as $page) {
            preg_match_all('/([\d.]+) ([\d.]+) m ([\d.]+) ([\d.]+) l S/', $page->get('Contents')->getContent(), $matches, PREG_SET_ORDER);
            $leftEdges = array_filter($matches, fn (array $line): bool => abs((float) $line[1] - 71.116) < 0.5 && abs((float) $line[3] - (float) $line[1]) < 0.01);
            $this->assertNotEmpty($leftEdges);
            $bottom = min(array_map(fn (array $line): float => min((float) $line[2], (float) $line[4]), $leftEdges));
            $bottomBorders = array_filter($matches, fn (array $line): bool => abs((float) $line[2] - $bottom) < 0.5
                && abs((float) $line[4] - $bottom) < 0.5
                && (float) $line[1] < 72 && (float) $line[3] > 540);
            $this->assertNotEmpty($bottomBorders, 'Every page containing report rows must close the table at its bottom edge.');
        }
        $text = preg_replace('/\s+/', ' ', $pdf->getText());
        $this->assertStringContainsString('accomplishment number 1', $text);
        $this->assertStringContainsString('accomplishment number 25', $text);
    }

    /** @return array<string, int> */
    private function signatories(User $user): array
    {
        return [
            'prepared_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Raymart N. Itanong', 'position' => 'Computer Programmer II', 'signatory_type' => 'prepared_by'])->id,
            'certified_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Wilfredo M. Galagala', 'position' => 'Information Technology Officer II', 'signatory_type' => 'certified_correct'])->id,
            'approved_by_id' => Signatory::create(['user_id' => $user->id, 'name' => 'Joyzel R. Odi', 'position' => 'PG Department Head', 'signatory_type' => 'approved'])->id,
        ];
    }
}
