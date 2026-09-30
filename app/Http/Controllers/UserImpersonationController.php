<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserImpersonationController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->is_admin && ! $request->session()->has('impersonator_id'), 403);
        abort_if($user->is_admin || $user->id === $request->user()->id, 403);

        $request->session()->put('impersonator_id', $request->user()->id);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'You are viewing the app as '.$user->name.'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $originalId = $request->session()->get('impersonator_id');
        abort_unless(is_int($originalId), 403);
        $original = User::find($originalId);
        abort_unless($original?->is_admin && $request->user()->id !== $original->id, 403);

        $request->session()->forget('impersonator_id');
        Auth::login($original);
        $request->session()->regenerate();

        return redirect()->route('users.index')->with('status', 'Returned to your admin account.');
    }
}
