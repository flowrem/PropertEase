<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Billing')] class extends Component
{
    //
};
?>

<section class="w-full">
    <x-feature-preview
        icon="banknotes"
        :heading="__('Billing')"
        :description="__('Your rent balance, due date, and full payment history in one place.')"
        :items="[
            __('Current balance & due date at a glance'),
            __('Rent and each utility itemised as separate lines, not one lump total'),
            __('Full payment history and receipts'),
            __('Reminders before the due date, escalating if a payment is late'),
        ]"
    />
</section>
