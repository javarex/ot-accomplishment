<?php

namespace App\Http\Controllers;

use App\Models\AccomplishmentReport;
use App\Models\Signatory;
use App\Services\Accomplishments\DtrImportStager;
use App\Services\Accomplishments\OvertimePayCalculator;
use App\Services\Accomplishments\ReportWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AccomplishmentReportController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AccomplishmentReport::class);

        $reports = AccomplishmentReport::query()
            ->select(['id', 'user_id', 'report_month', 'report_year', 'status'])
            ->with('user:id,name')
            ->withCount('entries')
            ->orderByDesc('report_year')
            ->orderByDesc('report_month')
            ->latest('id')
            ->paginate(15);

        return Inertia::render('reports/index', ['reports' => $reports, 'currentUserId' => $request->user()->id]);
    }

    public function show(AccomplishmentReport $report, OvertimePayCalculator $calculator): Response
    {
        Gate::authorize('view', $report);
        $report->load(['user:id,name', 'entries', 'preparedBy', 'certifiedBy', 'approvedBy']);
        $canViewComputation = Gate::allows('viewComputation', $report);
        $overtimePay = $canViewComputation ? $calculator->calculate($report) : null;
        if (! $canViewComputation) {
            $report->makeHidden(['hourly_rate', 'daily_rate', 'jo_tax_percent']);
            $report->entries->each->makeHidden('hourly_rate');
        }

        return Inertia::render('reports/show', ['report' => $report, 'canEdit' => Gate::allows('update', $report), 'canSetHourlyRate' => Gate::allows('editComputation', $report), 'canViewComputation' => $canViewComputation, 'overtimePay' => $overtimePay]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', AccomplishmentReport::class);

        return Inertia::render('reports/editor', [
            'report' => null,
            'canViewComputation' => $request->user()->hasPermission('view_ot_computation') || $request->user()->hasPermission('edit_ot_computation'),
            'canEditComputation' => $request->user()->hasPermission('edit_ot_computation'),
            'signatories' => $this->signatories(),
        ]);
    }

    public function store(Request $request, ReportWriter $writer, DtrImportStager $stager): RedirectResponse
    {
        Gate::authorize('create', AccomplishmentReport::class);
        if (($request->exists('hourly_rate') || $request->exists('daily_rate') || $request->exists('jo_tax_percent')) && ! $request->user()->hasPermission('edit_ot_computation')) {
            abort(403);
        }
        $dtrData = $request->validate([
            'dtr' => ['sometimes', 'file', 'mimes:pdf', 'extensions:pdf', 'max:10240'],
            'dtr_previewed' => ['sometimes', 'boolean'],
            'dtr_import_dates' => ['sometimes', 'array'],
            'dtr_import_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ]);
        $dtr = $request->file('dtr');
        $previewedDtr = $request->boolean('dtr_previewed');

        if ($dtr && $request->boolean('finalize')) {
            throw ValidationException::withMessages(['dtr' => 'Review the DTR before finalizing the report.']);
        }

        if ($dtr) {
            $storedPath = null;
            try {
                $import = DB::transaction(function () use ($request, $writer, $stager, $dtr, $dtrData, $previewedDtr, &$storedPath) {
                    $parsed = $stager->parse($dtr);
                    $report = $writer->save(null, $request->user()->id, [
                        ...$request->all(),
                        'report_month' => $parsed['month'],
                        'report_year' => $parsed['year'],
                    ]);

                    if ($previewedDtr) {
                        $overtimeDates = collect($parsed['entries'])
                            ->filter(fn (array $entry): bool => ($entry['overtime_minutes'] ?? 0) > 0)
                            ->pluck('date')->all();

                        foreach ($dtrData['dtr_import_dates'] ?? [] as $date) {
                            if (! in_array($date, $overtimeDates, true) || ! $report->entries()->whereDate('accomplishment_date', $date)->exists()) {
                                throw ValidationException::withMessages(['dtr_import_dates' => 'A selected DTR date is missing from the report. Process the DTR again.']);
                            }
                        }
                    }

                    $import = $stager->stage($report, $request->user()->id, $dtr, $parsed);
                    $storedPath = $import->file_path;

                    if ($previewedDtr) {
                        foreach ($dtrData['dtr_import_dates'] ?? [] as $date) {
                            $dtrEntry = $import->entries()->whereDate('work_date', $date)->firstOrFail();
                            $reportEntry = $report->entries()->whereDate('accomplishment_date', $date)->firstOrFail();

                            $reportEntry->update(['dtr_entry_id' => $dtrEntry->id]);
                            $dtrEntry->update(['selected_for_import' => true]);
                        }

                        if (($dtrData['dtr_import_dates'] ?? []) !== []) {
                            $import->update(['import_status' => 'imported']);
                        }
                    }

                    return $import;
                });
            } catch (Throwable $exception) {
                if ($storedPath !== null) {
                    Storage::delete($storedPath);
                }

                throw $exception;
            }

            if ($previewedDtr) {
                return redirect()->route('reports.edit', $import->accomplishment_report_id)
                    ->with('status', 'Report saved with DTR records.');
            }

            return redirect()->route('reports.dtr.show', [$import->accomplishment_report_id, $import])
                ->with('status', 'Review the detected DTR dates before importing them.');
        }
        $report = $writer->save(null, $request->user()->id, $request->all());

        return redirect()->route('reports.edit', $report)->with('status', 'Report saved.');
    }

    public function edit(Request $request, AccomplishmentReport $report): Response
    {
        Gate::authorize('update', $report);
        $report->load(['entries', 'preparedBy', 'certifiedBy', 'approvedBy', 'dtrImports' => fn ($query) => $query
            ->select(['id', 'accomplishment_report_id', 'original_filename', 'import_status'])
            ->latest()]);

        $canViewComputation = Gate::allows('viewComputation', $report);
        if (! $canViewComputation) {
            $report->makeHidden(['hourly_rate', 'daily_rate', 'jo_tax_percent']);
            $report->entries->each->makeHidden('hourly_rate');
        }

        return Inertia::render('reports/editor', [
            'report' => $report,
            'canViewComputation' => $canViewComputation,
            'canEditComputation' => Gate::allows('editComputation', $report),
            'signatories' => $this->signatories(array_filter([$report->prepared_by_id, $report->certified_by_id, $report->approved_by_id])),
        ]);
    }

    public function update(Request $request, AccomplishmentReport $report, ReportWriter $writer): RedirectResponse
    {
        Gate::authorize('update', $report);
        if (($request->exists('hourly_rate') || $request->exists('daily_rate') || $request->exists('jo_tax_percent')) && ! Gate::allows('editComputation', $report)) {
            abort(403);
        }
        $writer->save($report, $report->user_id, $request->all());

        return redirect()->route('reports.edit', $report)->with('status', 'Report saved.');
    }

    public function updateHourlyRate(Request $request, AccomplishmentReport $report): RedirectResponse
    {
        abort_unless(Gate::allows('editComputation', $report), 403);
        $data = $request->validate(['hourly_rate' => ['required', 'numeric', 'decimal:0,2', 'between:0,99999999.99']]);
        $report->update($data);

        return back()->with('status', 'Hourly rate updated.');
    }

    public function updateDailyRate(Request $request, AccomplishmentReport $report): RedirectResponse
    {
        abort_unless(Gate::allows('editComputation', $report), 403);
        $data = $request->validate([
            'daily_rate' => ['required', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            'jo_tax_percent' => ['sometimes', 'numeric', 'decimal:0,2', 'between:0,100'],
        ]);
        $report->update($data);

        return back()->with('status', 'JO rate and tax updated.');
    }

    public function destroy(AccomplishmentReport $report): RedirectResponse
    {
        Gate::authorize('delete', $report);
        $report->delete();

        return redirect()->route('reports.index')->with('status', 'Report deleted.');
    }

    /**
     * @param  array<int, int>  $selectedIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function signatories(array $selectedIds = []): array
    {
        return Signatory::query()->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $selectedIds))
            ->orderBy('name')->get(['id', 'name', 'position', 'signatory_type'])->groupBy('signatory_type')->toArray();
    }
}
