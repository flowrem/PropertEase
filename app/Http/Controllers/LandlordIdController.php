<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LandlordIdController extends Controller
{
    /**
     * Stream a landlord's submitted ID to a Super Admin. The route sits behind
     * the Super Admin middleware, and the response is never cached.
     */
    public function __invoke(int $team): StreamedResponse
    {
        $record = Team::query()->findOrFail($team);

        $path = $record->verification_id_path;

        abort_if($path === null || $record->verification_id_pruned_at !== null, 404);

        $disk = Storage::disk(config('filesystems.sensitive_disk'));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
