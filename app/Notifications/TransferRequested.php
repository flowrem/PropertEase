<?php

namespace App\Notifications;

use App\Models\TransferRequest;
use Illuminate\Notifications\Notification;

class TransferRequested extends Notification
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
     * Opens the request on the landlord's Transfers page.
     *
     * @return array{team_id: int, transfer_request_id: int, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        $this->transfer->loadMissing(['tenant', 'fromUnit', 'toUnit']);

        return [
            'team_id' => $this->transfer->team_id,
            'transfer_request_id' => $this->transfer->id,
            'message' => __(':name asked to move from Unit :from to Unit :to.', [
                'name' => $this->transfer->tenant->name,
                'from' => $this->transfer->fromUnit->unit_number,
                'to' => $this->transfer->toUnit->unit_number,
            ]),
            'route' => 'transfers',
            'route_parameters' => ['transfer' => $this->transfer->id],
        ];
    }
}
