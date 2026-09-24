<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('reservations:prune-files')]
#[Description('Delete valid ID and payment proof files from rejected or cancelled reservations older than 30 days')]
class PruneReservationFiles extends Command
{
    private const RETENTION_DAYS = 30;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $disk = Storage::disk(config('filesystems.sensitive_disk'));
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $pruned = 0;

        Reservation::query()
            ->whereIn('status', [ReservationStatus::Rejected->value, ReservationStatus::Cancelled->value])
            ->whereNull('files_pruned_at')
            ->whereRaw('coalesce(cancelled_at, reviewed_at) < ?', [$cutoff])
            ->each(function (Reservation $reservation) use ($disk, &$pruned) {
                $disk->delete(array_filter([$reservation->valid_id_path, $reservation->downpayment_proof_path]));

                $reservation->forceFill(['files_pruned_at' => now()])->save();
                $pruned++;
            });

        $this->info("Pruned files for {$pruned} reservation(s).");

        return self::SUCCESS;
    }
}
