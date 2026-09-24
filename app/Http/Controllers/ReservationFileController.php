<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationFileController extends Controller
{
    /**
     * Stream an applicant's valid ID or payment proof from the private disk.
     * Only the reservation's own team can read it, and it is never cached.
     */
    public function __invoke(Request $request, string $currentTeam, int $reservation, string $kind): StreamedResponse
    {
        $record = Reservation::query()
            ->where('team_id', $request->user()->current_team_id)
            ->findOrFail($reservation);

        Gate::authorize('view', $record);

        $path = match ($kind) {
            'id' => $record->valid_id_path,
            'proof' => $record->downpayment_proof_path,
            default => abort(404),
        };

        abort_if($path === null || ! $record->hasFiles(), 404);

        $disk = Storage::disk(config('filesystems.sensitive_disk'));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
