<?php

namespace App\Http\Controllers;

use App\Models\AccomplishmentReport;
use App\Models\DtrImport;
use App\Services\Accomplishments\DtrImportStager;
use App\Services\Accomplishments\OvertimeQuantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class DtrImportController extends Controller
{
    public function preview(Request $request, DtrImportStager $stager): JsonResponse
    {
        Gate::authorize('create', AccomplishmentReport::class);
        $request->validate(['dtr' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240']]);
        $parsed = $stager->parse($request->file('dtr'));

        return response()->json([
            'employee_name' => $parsed['employee_name'],
            'month' => $parsed['month'],
            'year' => $parsed['year'],
            'entries' => $parsed['entries'],
        ]);
    }

    public function store(Request $request, AccomplishmentReport $report, DtrImportStager $stager): RedirectResponse
    {
        Gate::authorize('importDtr', $report);
        $request->validate(['dtr' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240']]);
        $import = $stager->stage($report, $request->user()->id, $request->file('dtr'));

        return redirect()->route('reports.dtr.show', [$report, $import])
            ->with('status', $import->wasRecentlyCreated ? 'Review the detected DTR dates before importing them.' : 'This DTR was already uploaded. Review the existing import.');
    }

    public function show(AccomplishmentReport $report, DtrImport $dtrImport): Response
    {
        Gate::authorize('importDtr', $report);
        abort_unless($dtrImport->accomplishment_report_id === $report->id, 404);
        $dtrImport->load(['entries' => fn ($query) => $query->select([
            'id', 'dtr_import_id', 'work_date', 'am_in', 'am_out', 'pm_in', 'pm_out', 'overtime_minutes', 'remarks', 'selected_for_import',
        ])]);

        return Inertia::render('dtr/review', [
            'report' => $report->only(['id', 'report_month', 'report_year', 'quantity_mode']),
            'import' => $dtrImport->only(['id', 'employee_name', 'employee_id', 'month', 'year', 'original_filename', 'import_status', 'entries']),
            'existingDates' => $report->entries()->pluck('accomplishment_date')->map(fn ($date) => substr((string) $date, 0, 10))->all(),
        ]);
    }

    public function commit(Request $request, AccomplishmentReport $report, DtrImport $dtrImport): RedirectResponse
    {
        Gate::authorize('importDtr', $report);
        abort_unless($dtrImport->accomplishment_report_id === $report->id, 404);
        $data = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => ['required', 'integer', 'distinct'],
            'duplicate_action' => ['required', Rule::in(['skip', 'replace'])],
            'manual_quantities' => ['sometimes', 'array'],
            'manual_quantities.*' => ['nullable', 'string', 'max:255'],
        ]);

        $entries = $dtrImport->entries()->whereIn('id', $data['entry_ids'])->get();

        if ($entries->count() !== count($data['entry_ids'])) {
            throw ValidationException::withMessages(['entry_ids' => 'One or more selected rows are invalid.']);
        }

        $quantities = [];
        $minutesById = [];
        foreach ($entries as $entry) {
            $quantity = $entry->overtime_minutes
                ? OvertimeQuantity::format($entry->overtime_minutes)
                : trim((string) ($data['manual_quantities'][$entry->id] ?? ''));
            if ($quantity === '') {
                throw ValidationException::withMessages(['manual_quantities.'.$entry->id => 'Enter a quantity for the selected day.']);
            }
            if ($report->quantity_mode === 'time') {
                try {
                    $minutesById[$entry->id] = $entry->overtime_minutes ?: OvertimeQuantity::parse($quantity);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(['manual_quantities.'.$entry->id => 'Enter a time such as 2h 10m.']);
                }
                $quantities[$entry->id] = OvertimeQuantity::hours($minutesById[$entry->id]);
            } else {
                $minutesById[$entry->id] = null;
                $quantities[$entry->id] = $quantity;
            }
        }

        $skipped = 0;
        $imported = 0;
        DB::transaction(function () use ($report, $dtrImport, $entries, $quantities, $minutesById, $data, &$skipped, &$imported): void {
            foreach ($entries as $entry) {
                $date = substr((string) $entry->work_date, 0, 10);
                $existing = $report->entries()->whereDate('accomplishment_date', $date)->first();

                if ($existing && $data['duplicate_action'] === 'skip') {
                    $skipped++;

                    continue;
                }

                if ($existing) {
                    $existing->update(['quantity' => $quantities[$entry->id], 'quantity_mode' => $report->quantity_mode, 'time_minutes' => $minutesById[$entry->id], 'dtr_entry_id' => $entry->id]);
                } else {
                    $report->entries()->create([
                        'dtr_entry_id' => $entry->id,
                        'accomplishment_date' => $date,
                        'quantity' => $quantities[$entry->id],
                        'quantity_mode' => $report->quantity_mode,
                        'time_minutes' => $minutesById[$entry->id],
                        'task_accomplished' => null,
                        'sort_order' => $report->entries()->max('sort_order') + 1,
                    ]);
                }

                $entry->update(['selected_for_import' => true]);
                $imported++;
            }

            $dtrImport->update(['import_status' => 'imported']);
            if ($imported > 0) {
                $report->update(['status' => AccomplishmentReport::DRAFT, 'generated_at' => null]);
            }
        });

        return redirect()->route('reports.edit', $report)->with('status', 'DTR import completed.'.($skipped ? " {$skipped} existing date(s) skipped." : ''));
    }
}
