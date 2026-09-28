<?php

namespace App\Actions\Transfers;

use App\Enums\LeaseStatus;
use App\Enums\TransferStatus;
use App\Models\TransferRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TransferUpdated;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The landlord's decisions on a transfer before the move: approve (which
 * holds the new unit's slot until the move), reject, or cancel an approved
 * one. The tenant is told each time. Callers check who may decide.
 */
class ReviewTransfer
{
    /**
     * @throws ValidationException
     */
    public function approve(TransferRequest $transfer, User $reviewer, CarbonInterface $moveDate): void
    {
        if ($moveDate->isBefore(today())) {
            throw ValidationException::withMessages(['moveDate' => __('The move date can\'t be in the past.')]);
        }

        DB::transaction(function () use ($transfer, $reviewer, $moveDate) {
            $locked = TransferRequest::query()->lockForUpdate()->findOrFail($transfer->id);
            $toUnit = Unit::query()->lockForUpdate()->findOrFail($locked->to_unit_id);

            $this->ensure($locked->status === TransferStatus::Pending, __('This request is no longer pending.'));
            $this->ensure($locked->lease->status === LeaseStatus::Active, __('The tenant\'s lease has ended.'));
            $this->ensure($toUnit->hasRoomForAnotherTenant(), __('That unit has no room left.'));

            $locked->forceFill([
                'status' => TransferStatus::Approved,
                'move_date' => $moveDate->toDateString(),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $transfer->setRawAttributes($locked->getAttributes(), true);
        });

        $transfer->tenant->notify(new TransferUpdated($transfer));
    }

    /**
     * @throws ValidationException
     */
    public function reject(TransferRequest $transfer, User $reviewer, string $reason): void
    {
        $this->decide($transfer, $reviewer, $reason, TransferStatus::Pending, TransferStatus::Rejected);
    }

    /**
     * Cancel an approved transfer before the move, releasing the held slot.
     *
     * @throws ValidationException
     */
    public function cancel(TransferRequest $transfer, User $reviewer, string $reason): void
    {
        $this->decide($transfer, $reviewer, $reason, TransferStatus::Approved, TransferStatus::Cancelled);
    }

    /**
     * @throws ValidationException
     */
    private function decide(TransferRequest $transfer, User $reviewer, string $reason, TransferStatus $from, TransferStatus $to): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['decisionReason' => __('Give a reason. It is sent to the tenant.')]);
        }

        DB::transaction(function () use ($transfer, $reviewer, $reason, $from, $to) {
            $locked = TransferRequest::query()->lockForUpdate()->findOrFail($transfer->id);

            $this->ensure($locked->status === $from, $from === TransferStatus::Pending
                ? __('This request is no longer pending.')
                : __('Only an approved transfer can be cancelled.'));

            $locked->forceFill([
                'status' => $to,
                'decision_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $transfer->setRawAttributes($locked->getAttributes(), true);
        });

        $transfer->tenant->notify(new TransferUpdated($transfer));
    }

    /**
     * @throws ValidationException
     */
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['transfer' => $message]);
        }
    }
}
