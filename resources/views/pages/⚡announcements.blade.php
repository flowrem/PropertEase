<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Announcements')] class extends Component
{
    //
};
?>

<section class="w-full">
    <x-feature-preview
        icon="megaphone"
        :heading="__('Announcements')"
        :description="__('Scheduled maintenance, inspections, and utility interruptions from your landlord.')"
        :items="[
            __('Utility interruption notices (water, power, internet)'),
            __('Scheduled maintenance and inspection dates'),
            __('General notices from your landlord'),
        ]"
    />
</section>
