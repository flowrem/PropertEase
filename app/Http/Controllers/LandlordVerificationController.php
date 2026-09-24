<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LandlordVerificationController extends Controller
{
    /**
     * Show a landlord where their approval stands.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $team = $request->user()->teamAwaitingApproval();

        if (! $team) {
            return redirect()->route('home');
        }

        return view('landlord.verification', ['team' => $team]);
    }

    /**
     * Replace the ID of a rejected landlord team and send it back for review.
     */
    public function resubmit(Request $request): RedirectResponse
    {
        $team = $request->user()->teamAwaitingApproval();

        abort_unless($team?->isRejected(), 403);

        $validated = Validator::make(
            $request->all(),
            CreateNewUser::verificationRules(),
            CreateNewUser::verificationMessages(),
        )->validate();

        $disk = Storage::disk(config('filesystems.sensitive_disk'));

        if ($team->verification_id_path) {
            $disk->delete($team->verification_id_path);
        }

        $team->forceFill([
            'verification_id_path' => $validated['verification_id']->store("landlord-ids/{$team->id}", config('filesystems.sensitive_disk')),
            'verification_submitted_at' => now(),
            'verification_id_pruned_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        return redirect()->route('landlord.verification')->with('status', __('ID submitted. We will review it soon.'));
    }
}
