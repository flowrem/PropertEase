<?php

namespace App\Notifications;

use App\Enums\TransferStatus;
use App\Models\TransferRequest;
use Illuminate\Notifications\Notification;

class TransferUpdated extends Notification
{
    public function __construct(public TransferRequest $transfer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Tells the tenant what the landlord decided, or that the move is done.
     * Opens their Transfer page.
     *
     * @return array{team_id: int, transfer_request_id: int, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        $this->transfer->loadMissing('toUnit');
        $unit = $this->transfer->toUnit->unit_number;

        return [
            'team_id' => $this->transfer->team_id,
            'transfer_request_id' => $this->transfer->id,
            'message' => match ($this->transfer->status) {
                TransferStatus::Approved => __('Your move to Unit :unit was approved for :date.', ['unit' => $unit, 'date' => $this->transfer->move_date?->format('M j, Y')]),
                TransferStatus::Rejected => __('Your request to move to Unit :unit was not approved: :reason', ['unit' => $unit, 'reason' => $this->transfer->decision_reason]),
                TransferStatus::Cancelled => __('Your approved move to Unit :unit was cancelled: :reason', ['unit' => $unit, 'reason' => $this->transfer->decision_reason]),
                TransferStatus::Completed => __('You have moved to Unit :unit. Please read and agree to your new contract.', ['unit' => $unit]),
                TransferStatus::Pending => __('Your request to move to Unit :unit is waiting for your landlord.', ['unit' => $unit]),
            },
            'route' => $this->transfer->status === TransferStatus::Completed ? 'contract' : 'transfer',
            'route_parameters' => [],
        ];
    }
}
