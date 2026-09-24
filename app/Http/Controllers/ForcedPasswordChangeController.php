<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeTemporaryPasswordRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ForcedPasswordChangeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('home');
        }

        return view('auth.change-temporary-password');
    }

    /**
     * Replace the temporary password, then sign the user out so they log in
     * again with the password they just chose.
     */
    public function store(ChangeTemporaryPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->must_change_password, 403);

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'remember_token' => null,
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('Password updated. Log in with your new password.'));
    }
}
