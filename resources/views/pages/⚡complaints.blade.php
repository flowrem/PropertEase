<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Complaints')] class extends Component
{
    //
};
?>

<section class="w-full">
    <x-feature-preview
        icon="flag"
        :heading="__('Complaints')"
        :description="__('Raise a concern with your landlord and follow it through to a response.')"
        :items="[
            __('Submit a complaint, kept separate from maintenance requests'),
            __('Status tracking so nothing goes unanswered'),
            __('A record of what was raised and how it was resolved'),
        ]"
    />
</section>
