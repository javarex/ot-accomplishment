<?php

namespace App\Http\Controllers;

use App\Models\AccomplishmentReport;
use App\Models\Signatory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SignatoryController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('signatories/index', [
            'signatories' => Signatory::query()->where('user_id', $request->user()->id)->orderBy('signatory_type')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $signatory = Signatory::create([...$data, 'user_id' => $request->user()->id]);

        return back()->with('status', 'Signatory saved.')
            ->with('createdSignatory', ['id' => $signatory->id, 'type' => $signatory->signatory_type]);
    }

    public function update(Request $request, Signatory $signatory): RedirectResponse
    {
        abort_unless($signatory->user_id === $request->user()->id, 403);
        $data = $this->validated($request);

        if ($data['signatory_type'] !== $signatory->signatory_type && AccomplishmentReport::query()
            ->where('prepared_by_id', $signatory->id)
            ->orWhere('certified_by_id', $signatory->id)
            ->orWhere('approved_by_id', $signatory->id)
            ->exists()) {
            throw ValidationException::withMessages(['signatory_type' => 'This signatory is used in a report and its section cannot be changed.']);
        }

        $signatory->update($data);

        return back()->with('status', 'Signatory updated.');
    }

    public function destroy(Request $request, Signatory $signatory): RedirectResponse
    {
        abort_unless($signatory->user_id === $request->user()->id, 403);
        $signatory->update(['is_active' => false]);

        return back()->with('status', 'Signatory deactivated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'signatory_type' => ['required', Rule::in(['prepared_by', 'certified_correct', 'approved'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
