<?php

namespace App\Actions\Landlords;

use App\Models\Team;
use App\Models\User;
use App\Notifications\LandlordApproved;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveLandlord
{
    /**
     * Approve a landlord team whose ID is waiting for review and email its owner.
     *
     * @throws ValidationException
     */
    public function handle(Team $team, User $admin): void
    {
        abort_unless($admin->is_super_admin, 403);

        DB::transaction(function () use ($team, $admin) {
            $locked = Team::query()->lockForUpdate()->findOrFail($team->id);

            if (! $locked->isAwaitingReview() || $locked->verification_id_path === null) {
                throw ValidationException::withMessages(['team' => __('This landlord is not waiting for review.')]);
            }

            $locked->forceFill([
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $team->setRawAttributes($locked->getAttributes(), true);
        });

        $team->owner()?->notify(new LandlordApproved($team));
    }
}
