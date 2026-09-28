<?php

namespace App\Actions\Units;

use App\Models\Unit;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves a unit's cover photo, shrunk to a small WebP first so the unit grid
 * stays light on slow mobile connections.
 */
class StoreUnitPhoto
{
    /**
     * The longest side of the stored photo, in pixels.
     */
    public const MAX_DIMENSION = 960;

    private const WEBP_QUALITY = 78;

    /**
     * @throws ValidationException
     */
    public function handle(Unit $unit, UploadedFile $photo): void
    {
        $image = $this->readImage($photo);
        $resized = $this->shrink($image);

        imagesavealpha($resized, true);

        ob_start();
        imagewebp($resized, null, self::WEBP_QUALITY);
        $contents = (string) ob_get_clean();

        $disk = Storage::disk(config('filesystems.media_disk'));
        $path = "units/{$unit->id}/".Str::random(24).'.webp';
        $disk->put($path, $contents);

        $previousPath = $unit->photo_path;
        $unit->forceFill(['photo_path' => $path])->save();

        if ($previousPath) {
            $disk->delete($previousPath);
        }
    }

    /**
     * Remove the unit's photo, so its card falls back to the placeholder.
     */
    public function remove(Unit $unit): void
    {
        if (! $unit->photo_path) {
            return;
        }

        Storage::disk(config('filesystems.media_disk'))->delete($unit->photo_path);
        $unit->forceFill(['photo_path' => null])->save();
    }

    /**
     * @throws ValidationException
     */
    private function readImage(UploadedFile $photo): GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($photo->getRealPath()));

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['photo' => __('This photo could not be read. Try a JPG or PNG.')]);
        }

        return $this->uprightFromCamera($image, $photo);
    }

    /**
     * Phone cameras store portrait shots sideways plus an orientation tag,
     * which GD ignores, so turn the pixels the way the tag says.
     */
    private function uprightFromCamera(GdImage $image, UploadedFile $photo): GdImage
    {
        if (! function_exists('exif_read_data') || $photo->getMimeType() !== 'image/jpeg') {
            return $image;
        }

        $orientation = @exif_read_data($photo->getRealPath())['Orientation'] ?? 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function shrink(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = self::MAX_DIMENSION / max($width, $height);

        if ($scale >= 1) {
            return $image;
        }

        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        return $resized instanceof GdImage ? $resized : $image;
    }
}
