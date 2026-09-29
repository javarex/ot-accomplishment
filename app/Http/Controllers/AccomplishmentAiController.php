<?php

namespace App\Http\Controllers;

use App\Contracts\AccomplishmentAiService;
use App\Models\AccomplishmentReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class AccomplishmentAiController extends Controller
{
    public function __invoke(Request $request, AccomplishmentReport $report, AccomplishmentAiService $ai): RedirectResponse
    {
        Gate::authorize('useAi', $report);
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);

        try {
            $suggestion = $ai->improve($data['text']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['ai' => $exception->getMessage()]);
        }

        return back()->with('aiSuggestion', [
            'original' => $data['text'],
            'suggestion' => $suggestion,
        ]);
    }
}
