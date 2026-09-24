<?php

namespace App\Actions\Landlords;

use App\Models\Team;
use App\Models\User;
use App\Notifications\LandlordRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectLandlord
{
    /**
     * Turn down a landlord team whose ID is waiting for review, with a reason
     * the landlord will see, and email its owner.
     *
     * @throws ValidationException
     */
    public function handle(Team $team, User $admin, string $reason): void
    {
        abort_unless($admin->is_super_admin, 403);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Give a reason for rejecting this landlord.')]);
        }

        DB::transaction(function () use ($team, $reason) {
            $locked = Team::query()->lockForUpdate()->findOrFail($team->id);

            if (! $locked->isAwaitingReview()) {
                throw ValidationException::withMessages(['team' => __('This landlord is not waiting for review.')]);
            }

            $locked->forceFill([
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $team->setRawAttributes($locked->getAttributes(), true);
        });

        $team->owner()?->notify(new LandlordRejected($team));
    }
}
