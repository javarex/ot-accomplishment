<?php

namespace App\Http\Controllers;

use App\Contracts\DtrParser;
use App\Models\AccomplishmentReport;
use App\Models\DtrImport;
use App\Services\Accomplishments\OvertimeQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DtrImportController extends Controller
{
    public function store(Request $request, AccomplishmentReport $report, DtrParser $parser): RedirectResponse
    {
        Gate::authorize('importDtr', $report);
        $request->validate(['dtr' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240']]);
        $file = $request->file('dtr');
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = $report->dtrImports()->where('file_hash', $hash)->first();

        if ($existing) {
            return redirect()->route('reports.dtr.show', [$report, $existing])->with('status', 'This DTR was already uploaded. Review the existing import.');
        }

        $parsed = $parser->parse($file->getRealPath());

        if ($parsed['month'] !== $report->report_month || $parsed['year'] !== $report->report_year) {
            throw ValidationException::withMessages(['dtr' => 'The DTR period does not match the report period.']);
        }

        $path = $file->store('dtr-imports');

        if (! $path) {
            throw ValidationException::withMessages(['dtr' => 'The DTR file could not be stored.']);
        }

        try {
            $import = DB::transaction(function () use ($report, $request, $file, $path, $hash, $parsed): DtrImport {
                $import = $report->dtrImports()->create([
                    'user_id' => $request->user()->id,
                    'employee_name' => $parsed['employee_name'],
                    'employee_id' => $parsed['employee_id'],
                    'month' => $parsed['month'],
                    'year' => $parsed['year'],
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_hash' => $hash,
                    'import_status' => 'review',
                    'parsed_data' => $parsed,
                ]);

                foreach ($parsed['entries'] as $entry) {
                    $import->entries()->create([
                        'work_date' => $entry['date'],
                        'am_in' => $entry['am_in'],
                        'am_out' => $entry['am_out'],
                        'pm_in' => $entry['pm_in'],
                        'pm_out' => $entry['pm_out'],
                        'overtime_minutes' => $entry['overtime_minutes'],
                        'remarks' => $entry['remarks'],
                    ]);
                }

                return $import;
            });
        } catch (\Throwable $exception) {
            Storage::delete($path);
            throw $exception;
        }

        return redirect()->route('reports.dtr.show', [$report, $import]);
    }

    public function show(AccomplishmentReport $report, DtrImport $dtrImport): Response
    {
        Gate::authorize('importDtr', $report);
        abort_unless($dtrImport->accomplishment_report_id === $report->id, 404);
        $dtrImport->load(['entries' => fn ($query) => $query->select([
            'id', 'dtr_import_id', 'work_date', 'am_in', 'am_out', 'pm_in', 'pm_out', 'overtime_minutes', 'remarks', 'selected_for_import',
        ])]);

        return Inertia::render('dtr/review', [
            'report' => $report->only(['id', 'report_month', 'report_year']),
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
        foreach ($entries as $entry) {
            $quantity = $entry->overtime_minutes
                ? OvertimeQuantity::format($entry->overtime_minutes)
                : trim((string) ($data['manual_quantities'][$entry->id] ?? ''));
            if ($quantity === '') {
                throw ValidationException::withMessages(['manual_quantities.'.$entry->id => 'Enter a quantity for the selected day.']);
            }
            $quantities[$entry->id] = $quantity;
        }

        $skipped = 0;
        $imported = 0;
        DB::transaction(function () use ($report, $dtrImport, $entries, $quantities, $data, &$skipped, &$imported): void {
            foreach ($entries as $entry) {
                $date = substr((string) $entry->work_date, 0, 10);
                $existing = $report->entries()->whereDate('accomplishment_date', $date)->first();

                if ($existing && $data['duplicate_action'] === 'skip') {
                    $skipped++;

                    continue;
                }

                if ($existing) {
                    $existing->update(['quantity' => $quantities[$entry->id], 'dtr_entry_id' => $entry->id]);
                } else {
                    $report->entries()->create([
                        'dtr_entry_id' => $entry->id,
                        'accomplishment_date' => $date,
                        'quantity' => $quantities[$entry->id],
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
