<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PERFORMANCE — compress images.
 *
 * Uploaded images (profile photos in UsersController, the school
 * logo in SettingsController) were previously stored exactly as the
 * browser sent them — a phone camera photo picked as a "profile
 * photo" can easily be 4-8MB, all of which then gets served on every
 * page that shows that avatar. This service re-encodes an uploaded
 * image down to a sane max dimension and a compressed JPEG/WebP/PNG
 * before it's written to disk.
 *
 * Uses PHP's built-in GD extension only (no new Composer dependency
 * — if you'd rather standardize on Intervention Image for more
 * format/EXIF handling, `composer require intervention/image` and
 * swap this class's internals; the store()/storeAs() call sites
 * don't need to change).
 */
class ImageOptimizationService
{
    /** Longest edge an optimized image is allowed to keep. */
    private const MAX_DIMENSION = 1600;

    /** JPEG/WebP quality (0-100). */
    private const QUALITY = 78;

    /**
     * Compress + resize an uploaded image and store it, returning the
     * same kind of relative path Illuminate\Http\UploadedFile::store()
     * would. Falls back to the original, unmodified upload (still via
     * a normal store()) if GD isn't available or the file isn't a
     * format GD can decode — an upload should never hard-fail just
     * because optimization couldn't run.
     */
    public function storeOptimized(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        if (! extension_loaded('gd')) {
            return $file->store($directory, $disk);
        }

        try {
            [$image, $originalWidth, $originalHeight] = $this->decode($file);

            if (! $image) {
                return $file->store($directory, $disk);
            }

            // Keep the alpha channel. GD only writes transparency back out
            // when alpha blending is off and saveAlpha is on — this used to
            // be set only on the *resized* copy, so any logo <= 1600px was
            // saved with its transparent pixels turned solid BLACK.
            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);

            [$targetWidth, $targetHeight] = $this->scaledDimensions($originalWidth, $originalHeight);

            if ($targetWidth !== $originalWidth || $targetHeight !== $originalHeight) {
                $resized = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $originalWidth, $originalHeight);
                imagedestroy($image);
                $image = $resized;
            }

            $extension = $this->outputExtension($file, $image);
            $filename = Str::uuid()->toString().'.'.$extension;
            $path = trim($directory, '/').'/'.$filename;

            $encoded = $this->encode($image, $extension);
            imagedestroy($image);

            if ($encoded === null) {
                return $file->store($directory, $disk);
            }

            Storage::disk($disk)->put($path, $encoded);

            return $path;
        } catch (\Throwable $e) {
            // Never let a thumbnailing bug block an upload — fall back
            // to storing the original file and log for follow-up.
            Log::warning('ImageOptimizationService: falling back to unoptimized upload', [
                'error' => $e->getMessage(),
            ]);

            return $file->store($directory, $disk);
        }
    }

    /**
     * @return array{0: \GdImage|null, 1: int, 2: int}
     */
    private function decode(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $info = @getimagesize($path);

        if (! $info) {
            return [null, 0, 0];
        }

        [$width, $height, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            default => null,
        };

        return [$image ?: null, (int) $width, (int) $height];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaledDimensions(int $width, int $height): array
    {
        $longestEdge = max($width, $height);

        if ($longestEdge <= self::MAX_DIMENSION || $longestEdge === 0) {
            return [$width, $height];
        }

        $scale = self::MAX_DIMENSION / $longestEdge;

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    private function outputExtension(UploadedFile $file, \GdImage $image): string
    {
        // Keep PNG as PNG (logos are frequently transparent — a JPEG
        // re-encode would flatten transparency to a solid background).
        // A WebP/GIF that actually contains transparent pixels is also
        // written as PNG for the same reason. Everything else becomes
        // JPEG, which compresses far better than PNG for photographic
        // content like profile photos.
        if (strtolower($file->getClientOriginalExtension()) === 'png') {
            return 'png';
        }

        return $this->hasTransparency($image) ? 'png' : 'jpg';
    }

    /**
     * Samples a grid of pixels (cheap even on large images) and reports
     * whether any of them is see-through.
     */
    private function hasTransparency(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(max($width, $height) / 64));

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function encode(\GdImage $image, string $extension): ?string
    {
        ob_start();

        $ok = match ($extension) {
            'png' => imagepng($image, null, 6),
            default => imagejpeg($image, null, self::QUALITY),
        };

        $data = ob_get_clean();

        return $ok ? $data : null;
    }
}