<?php

namespace App\Services;

use App\Exceptions\LogoProcessingUnavailable;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LogoService
{
    /**
     * Maximum size (in pixels) of the longest side of the generated thumbnail.
     */
    public const MAX_DIMENSION = 200;

    /**
     * Generate an optimized thumbnail, store it on the public disk and replace
     * any previous logo. Returns the public URL of the new file.
     */
    public function store(UploadedFile $file, ?Tenant $tenant): string
    {
        $this->ensureGdAvailable();

        $source = imagecreatefromstring(file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new RuntimeException('Unable to decode the uploaded image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);

        [$targetWidth, $targetHeight] = $this->scaledDimensions($width, $height);

        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);

        $format = function_exists('imagewebp') ? 'webp' : 'jpeg';

        if ($format === 'jpeg') {
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefill($thumb, 0, 0, $white);
        } else {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled(
            $thumb,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        $extension = $format === 'webp' ? 'webp' : 'jpg';
        $filename = 'tenant-logos/'.Str::uuid().'.'.$extension;

        $disk = Storage::disk('public');
        $disk->makeDirectory(dirname($filename));

        $this->write($thumb, $disk->path($filename), $format);

        imagedestroy($source);
        imagedestroy($thumb);

        $this->deletePrior($tenant);

        return $disk->url($filename);
    }

    private function scaledDimensions(int $width, int $height): array
    {
        $longest = max($width, $height);

        if ($longest <= self::MAX_DIMENSION) {
            return [$width, $height];
        }

        $scale = self::MAX_DIMENSION / $longest;

        return [(int) round($width * $scale), (int) round($height * $scale)];
    }

    private function write(\GdImage $thumb, string $path, string $format): void
    {
        $quality = 90;

        if ($format === 'webp') {
            imagewebp($thumb, $path, $quality);

            return;
        }

        imagejpeg($thumb, $path, $quality);
    }

    private function deletePrior(?Tenant $tenant): void
    {
        if (! $tenant?->business_logo_url) {
            return;
        }

        $relative = $this->relativePath($tenant->business_logo_url);

        if ($relative !== null && Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->delete($relative);
        }
    }

    private function relativePath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path === false || $path === null) {
            return null;
        }

        $relative = Str::after($path, '/storage/');

        return $relative === $path ? null : $relative;
    }

    private function ensureGdAvailable(): void
    {
        if (! extension_loaded('gd')) {
            throw new LogoProcessingUnavailable('The GD extension is required to process logos.');
        }
    }
}
