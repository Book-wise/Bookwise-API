<?php

namespace App\Services;

use App\Exceptions\AvatarProcessingUnavailable;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AvatarService
{
    /**
     * Maximum size (in pixels) of the longest side of the generated thumbnail.
     *
     * Avatars render small in the UI (chip, list, profile hero) and are framed
     * with object-fit: cover, so a compact thumbnail is enough and keeps the
     * payload tiny.
     */
    public const MAX_DIMENSION = 128;

    /**
     * Generate an optimized thumbnail, store it on the public disk and replace
     * any previous avatar. Returns a PUBLIC-RELATIVE path (/storage/…) of the
     * new file.
     *
     * We deliberately return a relative path, not an absolute URL: the stored
     * value must not be tied to the origin that generated it (APP_URL). The
     * client resolves /storage/… against its own configured API base, so the
     * asset works across dev, prod, proxies and domain changes with a single
     * client-side setting.
     *
     * The raw upload is never persisted: the file is resampled down to
     * MAX_DIMENSION and re-encoded (WebP when available, JPEG otherwise).
     */
    public function store(UploadedFile $file, User $user): string
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

        $format = $this->encoder();

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
        $filename = 'user-avatars/'.Str::uuid().'.'.$extension;

        $disk = Storage::disk('public');
        $disk->makeDirectory(dirname($filename));

        $this->write($thumb, $disk->path($filename), $format);

        imagedestroy($source);
        imagedestroy($thumb);

        $this->deletePrior($user);

        return '/storage/'.$filename;
    }

    /**
     * Elimina el avatar del usuario (archivo + referencia). El frontend vuelve
     * al fallback (iniciales/icono). No-op si el usuario no tiene avatar.
     */
    public function remove(User $user): void
    {
        $this->deletePrior($user);
        $user->avatar_url = null;
        $user->save();
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

    private function deletePrior(User $user): void
    {
        if (! $user->avatar_url) {
            return;
        }

        $relative = $this->relativePath($user->avatar_url);

        if ($relative !== null && Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->delete($relative);
        }
    }

    /**
     * Prefer WebP when the GD build supports it (smaller, lossless alpha);
     * fall back to JPEG otherwise.
     */
    private function encoder(): string
    {
        return function_exists('imagewebp') ? 'webp' : 'jpeg';
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
            throw new AvatarProcessingUnavailable('The GD extension is required to process avatars.');
        }
    }
}
