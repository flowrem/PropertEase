<?php

namespace App\Actions\Reservations;

use App\Enums\TeamRole;
use App\Models\PaymentChannel;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\DownpaymentSubmitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SubmitDownpayment
{
    /**
     * Record the applicant's downpayment and its proof on a reserved
     * reservation, before its deadline, then tell the landlord and managers
     * so they can check it. The input is validated by the page; this checks
     * the reservation can still take it.
     *
     * @throws ValidationException
     */
    public function handle(Reservation $reservation, PaymentChannel $channel, string $amount, string $reference, UploadedFile $proof): void
    {
        $disk = config('filesystems.sensitive_disk');
        $proofPath = $proof->store("reservations/{$reservation->code}", $disk);

        if ($proofPath === false) {
            throw ValidationException::withMessages(['proof' => __('Your proof of payment could not be saved. Please try again.')]);
        }

        try {
            DB::transaction(function () use ($reservation, $channel, $amount, $reference, $proofPath) {
                $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

                if (! $locked->acceptsDownpayment()) {
                    throw ValidationException::withMessages(['proof' => $locked->isPastDeadline()
                        ? __('The deadline for this reservation has passed, so the downpayment can no longer be sent.')
                        : __('This reservation is not waiting for a downpayment.')]);
                }

                if ($locked->hasDownpaymentSent()) {
                    throw ValidationException::withMessages(['proof' => __('You already sent your downpayment. The landlord will check it.')]);
                }

                if ($channel->team_id !== $locked->team_id) {
                    throw ValidationException::withMessages(['payment_channel_id' => __('Choose one of this landlord\'s accounts.')]);
                }

                $locked->forceFill([
                    'downpayment_amount' => $amount,
                    'payment_channel_id' => $channel->id,
                    'downpayment_method' => $channel->method,
                    'downpayment_reference' => trim($reference),
                    'downpayment_proof_path' => $proofPath,
                    'downpayment_submitted_at' => now(),
                ])->save();

                $reservation->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (ValidationException $exception) {
            Storage::disk($disk)->delete($proofPath);

            throw $exception;
        }

        $reservation->team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->get()
            ->each(fn (User $member) => $member->notify(new DownpaymentSubmitted($reservation)));
    }
}
