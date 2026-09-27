<?php

use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component
{
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return EloquentCollection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): EloquentCollection
    {
        return Auth::user()->notificationsForTeam($this->team);
    }

    /**
     * Mark a notification read and go to what it is about.
     */
    public function open(string $notificationId): void
    {
        $notification = $this->notifications->firstWhere('id', $notificationId);

        abort_unless($notification, 404);

        $notification->markAsRead();

        $route = $notification->data['route'] ?? null;

        if (is_string($route) && Route::has($route)) {
            $this->redirect(route($route, $notification->data['route_parameters'] ?? []), navigate: true);

            return;
        }

        unset($this->notifications);
    }

    public function markRead(string $notificationId): void
    {
        $this->notifications->firstWhere('id', $notificationId)?->markAsRead();

        unset($this->notifications);
    }

    public function markAllRead(): void
    {
        $this->notifications->whereNull('read_at')->each(fn (DatabaseNotification $notification) => $notification->markAsRead());

        unset($this->notifications);

        Flux::toast(variant: 'success', text: __('All caught up.'));
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
            <flux:subheading>{{ __('Updates about :team, newest first.', ['team' => $this->team->name]) }}</flux:subheading>
        </div>

        @if ($this->notifications->whereNull('read_at')->isNotEmpty())
            <flux:button size="sm" icon="check" wire:click="markAllRead">{{ __('Mark all as read') }}</flux:button>
        @endif
    </div>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white">
        @forelse ($this->notifications as $notification)
            <div wire:key="notification-{{ $notification->id }}" @class(['flex items-start justify-between gap-3 px-4 py-3', 'bg-brand-50' => $notification->unread()])>
                <button type="button" wire:click="open('{{ $notification->id }}')" class="flex flex-1 items-start gap-3 text-start">
                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-brand-600' => $notification->unread(), 'bg-transparent' => $notification->read()])></span>
                    <span>
                        <span @class(['block text-sm', 'font-medium text-zinc-900' => $notification->unread(), 'text-zinc-700' => $notification->read()])>
                            {{ $notification->data['message'] ?? __('Notification') }}
                        </span>
                        <span class="block text-xs text-zinc-500">{{ $notification->created_at->diffForHumans() }}</span>
                    </span>
                </button>

                @if ($notification->unread())
                    <flux:button size="xs" variant="ghost" wire:click="markRead('{{ $notification->id }}')">{{ __('Mark read') }}</flux:button>
                @endif
            </div>
        @empty
            <flux:text class="px-4 py-6 text-center">{{ __('No notifications yet.') }}</flux:text>
        @endforelse
    </div>
</section>
