<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class UserImpersonationController extends Controller
{
    public function store(Request $request, User $user): Response
    {
        abort_unless($request->user()->is_admin && ! $request->session()->has('impersonator_id'), 403);
        abort_if($user->is_admin || $user->id === $request->user()->id, 403);

        $request->session()->put('impersonator_id', $request->user()->id);
        Auth::login($user);
        $request->session()->regenerate();

        Inertia::clearHistory();
        $request->session()->flash('status', 'You are viewing the app as '.$user->name.'.');

        return Inertia::location(route('dashboard'));
    }

    public function destroy(Request $request): Response
    {
        $originalId = $request->session()->get('impersonator_id');
        abort_unless(is_int($originalId), 403);
        $original = User::find($originalId);
        abort_unless($original?->is_admin && $request->user()->id !== $original->id, 403);

        $request->session()->forget('impersonator_id');
        Auth::login($original);
        $request->session()->regenerate();

        Inertia::clearHistory();
        $request->session()->flash('status', 'Returned to your admin account.');

        return Inertia::location(route('users.index'));
    }
}
