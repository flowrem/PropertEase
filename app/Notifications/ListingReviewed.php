<?php

namespace App\Notifications;

use App\Enums\ListingStatus;
use App\Models\UnitListing;
use Illuminate\Notifications\Notification;

class ListingReviewed extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public UnitListing $listing)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array{listing_id: int, title: string, status: string, message: string, reason: string|null}
     */
    public function toArray(object $notifiable): array
    {
        $approved = $this->listing->status === ListingStatus::Approved;

        return [
            'listing_id' => $this->listing->id,
            'title' => $this->listing->title,
            'status' => $this->listing->status->value,
            'message' => $approved
                ? __('Your listing ":title" was approved and is now public.', ['title' => $this->listing->title])
                : __('Your listing ":title" was rejected.', ['title' => $this->listing->title]),
            'reason' => $this->listing->rejection_reason,
        ];
    }
}
