<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('landlord-ids:prune')]
#[Description('Delete landlord ID files 30 days after a Super Admin approved or rejected them')]
class PruneLandlordIds extends Command
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

        Team::query()
            ->whereNotNull('verification_id_path')
            ->whereNull('verification_id_pruned_at')
            ->whereRaw('coalesce(approved_at, rejected_at) < ?', [$cutoff])
            ->each(function (Team $team) use ($disk, &$pruned) {
                $disk->delete($team->verification_id_path);

                $team->forceFill(['verification_id_pruned_at' => now()])->save();
                $pruned++;
            });

        $this->info("Pruned IDs for {$pruned} landlord team(s).");

        return self::SUCCESS;
    }
}
