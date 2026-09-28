<?php

namespace App\Actions\Transfers;

use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Models\Lease;
use App\Models\TransferRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TransferRequested;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestTransfer
{
    /**
     * Ask to move the lease's tenant to another unit of the same landlord.
     * The unit must have room now; its slot is only held once the landlord
     * approves. One open request per lease. The landlord's side is told.
     *
     * @throws ValidationException
     */
    public function handle(Lease $lease, Unit $toUnit, string $reason, CarbonInterface $preferredDate): TransferRequest
    {
        $teamId = $lease->unit->property->team_id;

        $transfer = DB::transaction(function () use ($lease, $toUnit, $reason, $preferredDate, $teamId): TransferRequest {
            $locked = Lease::query()->lockForUpdate()->findOrFail($lease->id);

            $this->ensure($locked->status === LeaseStatus::Active, 'lease', __('Only a current lease can be transferred.'));
            $this->ensure($toUnit->property->team_id === $teamId, 'to_unit_id', __('Choose one of your landlord\'s units.'));
            $this->ensure($toUnit->id !== $locked->unit_id, 'to_unit_id', __('Choose a different unit from the one you are in.'));
            $this->ensure($toUnit->hasRoomForAnotherTenant(), 'to_unit_id', __('That unit has no room right now.'));
            $this->ensure(
                TransferRequest::query()->where('lease_id', $locked->id)->open()->doesntExist(),
                'to_unit_id',
                __('You already have a transfer request in progress.'),
            );

            return TransferRequest::create([
                'team_id' => $teamId,
                'lease_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'from_unit_id' => $locked->unit_id,
                'to_unit_id' => $toUnit->id,
                'reason' => trim($reason),
                'preferred_date' => $preferredDate->toDateString(),
            ]);
        });

        $transfer->team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value, TeamRole::Member->value])
            ->get()
            ->each(fn (User $member) => $member->notify(new TransferRequested($transfer)));

        return $transfer;
    }

    /**
     * @throws ValidationException
     */
    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
