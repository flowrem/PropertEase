<?php

namespace App\Http\Controllers;

use App\Models\Concern;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConcernPhotoController extends Controller
{
    /**
     * Stream a report's photo from the private disk, to the tenant who sent
     * it and the landlord's side of their team only, never cached.
     */
    public function __invoke(Request $request, string $currentTeam, int $concern): StreamedResponse
    {
        $record = Concern::query()
            ->whereHas('lease.unit.property', fn ($properties) => $properties->where('team_id', $request->user()->current_team_id))
            ->findOrFail($concern);

        Gate::authorize('view', $record);

        abort_if($record->photo_path === null, 404);

        $disk = Storage::disk(config('filesystems.sensitive_disk'));

        abort_unless($disk->exists($record->photo_path), 404);

        return $disk->response($record->photo_path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
