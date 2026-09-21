<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Maintenance')] class extends Component {}; ?>

<section class="w-full">
    <x-feature-preview
        icon="wrench-screwdriver"
        :heading="__('Maintenance')"
        :description="__('Report a repair issue and track it from submission to resolved.')"
        :items="[
            __('Submit a request with a description and photo'),
            __('Status tracking: Pending → In Progress → Resolved'),
            __('A timestamped record of what was reported and when'),
        ]"
    />
</section>
