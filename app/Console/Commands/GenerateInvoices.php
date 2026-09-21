<?php

namespace App\Console\Commands;

use App\Actions\Invoices\GenerateDueInvoicesForLease;
use App\Enums\LeaseStatus;
use App\Models\Lease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoices:generate')]
#[Description('Generate any invoices active leases are due for, up to today')]
class GenerateInvoices extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GenerateDueInvoicesForLease $generateDueInvoices): void
    {
        $generated = 0;

        Lease::where('status', LeaseStatus::Active)
            ->chunkById(100, function ($leases) use ($generateDueInvoices, &$generated) {
                foreach ($leases as $lease) {
                    $generated += $generateDueInvoices->handle($lease)->count();
                }
            });

        $this->info("Generated {$generated} invoice(s).");
    }
}
