<?php

namespace App\Http\Controllers;

use App\Models\AccomplishmentReport;
use App\Models\Signatory;
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

    public function show(AccomplishmentReport $report): Response
    {
        Gate::authorize('view', $report);
        $report->load(['user:id,name', 'entries', 'preparedBy', 'certifiedBy', 'approvedBy']);

        return Inertia::render('reports/show', ['report' => $report, 'canEdit' => Gate::allows('update', $report)]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', AccomplishmentReport::class);

        return Inertia::render('reports/editor', [
            'report' => null,
            'signatories' => $this->signatories(),
        ]);
    }

    public function store(Request $request, ReportWriter $writer): RedirectResponse
    {
        Gate::authorize('create', AccomplishmentReport::class);
        $report = $writer->save(null, $request->user()->id, $request->all());

        return redirect()->route('reports.edit', $report)->with('status', 'Report saved.');
    }

    public function edit(Request $request, AccomplishmentReport $report): Response
    {
        Gate::authorize('update', $report);
        $report->load(['entries', 'preparedBy', 'certifiedBy', 'approvedBy', 'dtrImports' => fn ($query) => $query
            ->select(['id', 'accomplishment_report_id', 'original_filename', 'import_status'])
            ->latest()]);

        return Inertia::render('reports/editor', [
            'report' => $report,
            'signatories' => $this->signatories(array_filter([$report->prepared_by_id, $report->certified_by_id, $report->approved_by_id])),
        ]);
    }

    public function update(Request $request, AccomplishmentReport $report, ReportWriter $writer): RedirectResponse
    {
        Gate::authorize('update', $report);
        $writer->save($report, $report->user_id, $request->all());

        return redirect()->route('reports.edit', $report)->with('status', 'Report saved.');
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
