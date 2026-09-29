<?php

namespace App\Http\Controllers;

use App\Models\AccomplishmentReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $reports = AccomplishmentReport::query()->where('user_id', $request->user()->id);
        $counts = (clone $reports)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('dashboard', [
            'summary' => [
                'total' => $counts->sum(),
                'draft' => (int) ($counts['draft'] ?? 0),
                'finalized' => (int) ($counts['finalized'] ?? 0),
                'generated' => (int) ($counts['generated'] ?? 0),
            ],
            'recentReports' => (clone $reports)
                ->select(['id', 'user_id', 'report_month', 'report_year', 'status', 'updated_at'])
                ->withCount('entries')
                ->latest('updated_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
