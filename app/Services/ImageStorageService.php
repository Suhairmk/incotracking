<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImageStorageService
{
    /**
     * Validate, orient, resize and compress an uploaded photograph.
     *
     * @return array{path: string, thumb_path: string}
     */
    public function store(UploadedFile $file, string $directory, string $baseName): array
    {
        $sourcePath = $file->getRealPath();
        $info = @getimagesize($sourcePath);

        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([
                'photo' => 'The photo must be a JPEG, PNG or WebP image.',
            ]);
        }

        [$width, $height] = $info;
        $maxPixels = (int) config('tracking.photo.max_pixels', 24_000_000);

        if ($width < 1 || $height < 1 || ($width * $height) > $maxPixels) {
            throw ValidationException::withMessages([
                'photo' => 'The photo dimensions are not supported.',
            ]);
        }

        $image = @imagecreatefromstring((string) file_get_contents($sourcePath));

        if (! $image) {
            throw ValidationException::withMessages([
                'photo' => 'The photo could not be processed.',
            ]);
        }

        try {
            $image = $this->applyExifOrientation($image, $sourcePath);
            $mainJpeg = $this->resizeToJpeg(
                $image,
                (int) config('tracking.photo.max_width', 1600),
                (int) config('tracking.photo.jpeg_quality', 82)
            );
            $thumbJpeg = $this->resizeToJpeg(
                $image,
                (int) config('tracking.photo.thumbnail_width', 320),
                max(60, (int) config('tracking.photo.jpeg_quality', 82) - 10)
            );

            $directory = trim($directory, '/');
            $baseName = preg_replace('/[^A-Za-z0-9_-]/', '', $baseName) ?: 'photo';
            $path = "{$directory}/{$baseName}.jpg";
            $thumbnailPath = "{$directory}/{$baseName}_thumb.jpg";

            $this->putTempFile($path, $mainJpeg);
            $this->putTempFile($thumbnailPath, $thumbJpeg);

            return ['path' => $path, 'thumb_path' => $thumbnailPath];
        } finally {
            imagedestroy($image);
        }
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(config('filesystems.default'))->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && Storage::disk(config('filesystems.default'))->exists($path);
    }

    private function applyExifOrientation(\GdImage $image, string $sourcePath): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($sourcePath);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };

        if ($rotated !== false) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /**
     * @return string Path to a temporary JPEG file.
     */
    private function resizeToJpeg(\GdImage $image, int $maxWidth, int $quality): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $targetWidth = min($width, max(1, $maxWidth));
        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $canvas) {
            throw new RuntimeException('Unable to allocate image buffer.');
        }

        // Flatten transparent PNG/WebP images onto white so thumbnails remain readable.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        $temp = tempnam(sys_get_temp_dir(), 'tracking_img_');

        if ($temp === false) {
            imagedestroy($canvas);

            throw new RuntimeException('Unable to create a temporary image file.');
        }

        $jpegPath = $temp.'.jpg';
        @unlink($temp);
        imagejpeg($canvas, $jpegPath, max(1, min(100, $quality)));
        imagedestroy($canvas);

        return $jpegPath;
    }

    private function putTempFile(string $path, string $tempPath): void
    {
        $handle = fopen($tempPath, 'rb');

        if ($handle === false) {
            @unlink($tempPath);

            throw new RuntimeException('Unable to read processed image.');
        }

        try {
            $stored = Storage::disk(config('filesystems.default'))->put($path, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($tempPath);
        }

        if (! $stored) {
            throw new RuntimeException('Unable to store processed image.');
        }
    }
}
