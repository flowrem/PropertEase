<?php

namespace App\Console\Commands;

use App\Actions\Reservations\ExpireReservations as ExpireOverdueReservations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reservations:expire')]
#[Description('Mark reserved reservations whose downpayment deadline passed with nothing sent as expired, and email the applicants')]
class ExpireReservations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ExpireOverdueReservations $expireReservations): int
    {
        $expired = $expireReservations->handle();

        $this->info("Expired {$expired} reservation(s).");

        return self::SUCCESS;
    }
}
