<?php

namespace App\Http\Controllers;

use App\Models\AccomplishmentReport;
use App\Models\Signatory;
use App\Services\Accomplishments\OvertimePayCalculator;
use App\Services\Accomplishments\ReportWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
            $report->makeHidden('hourly_rate');
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

    public function store(Request $request, ReportWriter $writer): RedirectResponse
    {
        Gate::authorize('create', AccomplishmentReport::class);
        if ($request->exists('hourly_rate') && ! $request->user()->hasPermission('edit_ot_computation')) {
            abort(403);
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
            $report->makeHidden('hourly_rate');
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
        if ($request->exists('hourly_rate') && ! Gate::allows('editComputation', $report)) {
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
