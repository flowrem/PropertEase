<?php

use App\Data\UserTeam;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function currentTeamName(): ?string
    {
        return Auth::user()->currentTeam?->name;
    }

    /**
     * @return Collection<int, UserTeam>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }

    public function switchTeam(string $slug): void
    {
        $user = Auth::user();
        $team = Team::where('slug', $slug)->firstOrFail();

        abort_unless($user->belongsToTeam($team), 403);

        $currentTeamSlug = $user->currentTeam?->slug;
        $referer = request()->header('Referer');

        $user->switchTeam($team);

        if (! $referer) {
            $this->redirectRoute('dashboard', ['current_team' => $team->slug], navigate: true);

            return;
        }

        if (! $currentTeamSlug) {
            $this->redirect($referer, navigate: true);

            return;
        }

        $this->redirect(
            $this->replaceCurrentTeamInReferer($referer, $currentTeamSlug, $team->slug) ?? $referer,
            navigate: true,
        );
    }

    protected function replaceCurrentTeamInReferer(string $referer, string $currentTeamSlug, string $newTeamSlug): ?string
    {
        $redirectTo = preg_replace(
            '#/'.preg_quote($currentTeamSlug, '#').'(?=/|\?|$)#',
            '/'.$newTeamSlug,
            $referer,
            1,
        );

        return preg_replace(
            '#([?&]current_team=)'.preg_quote($currentTeamSlug, '#').'(?=&|$)#',
            '$1'.$newTeamSlug,
            $redirectTo ?? $referer,
            1,
        );
    }
}; ?>

<div>
    @if ($this->teams->count() > 1)
        <flux:dropdown position="bottom" align="start">
            <flux:button variant="ghost" class="group w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center" data-test="team-switcher-trigger">
                <flux:icon name="users" class="hidden size-4 in-data-flux-sidebar-collapsed-desktop:block" />
                <span class="truncate font-semibold in-data-flux-sidebar-collapsed-desktop:hidden">{{ $this->currentTeamName ?? __('Select team') }}</span>
                <flux:icon
                    name="chevrons-up-down"
                    variant="micro"
                    class="ms-auto size-4 in-data-flux-sidebar-collapsed-desktop:hidden"
                />
            </flux:button>

            <flux:menu class="min-w-56">
                <flux:menu.heading>{{ __('Switch account') }}</flux:menu.heading>

                @foreach ($this->teams as $team)
                    <flux:menu.item
                        wire:click="switchTeam('{{ $team->slug }}')"
                        class="cursor-pointer"
                        data-test="team-switcher-item"
                    >
                        <div class="flex w-full items-center justify-between">
                            <span>{{ $team->name }}</span>
                            @if ($team->isCurrent)
                                <flux:icon name="check" class="size-4" />
                            @endif
                        </div>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @else
        <div class="flex h-8 w-full items-center gap-2 px-3 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:px-0" data-test="team-switcher-static">
            <flux:icon name="users" class="hidden size-4 in-data-flux-sidebar-collapsed-desktop:block" />
            <span class="truncate text-sm font-semibold in-data-flux-sidebar-collapsed-desktop:hidden">{{ $this->currentTeamName ?? __('No team') }}</span>
        </div>
    @endif
</div>
