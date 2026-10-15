<?php

namespace App\Services\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Decodes the uploaded image with GD, resizes it and writes a NEW file. Whatever was inside the
 * original upload (extra bytes, scripts, metadata) is discarded. The stored name is random.
 */
class ImageUploader
{
    /**
     * @param  string  $format  'png' (keeps transparency) or 'jpeg'
     * @return string storage path on the "public" disk
     */
    public function store(UploadedFile $file, string $directory, int $maxWidth, int $maxHeight, string $format): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The PHP GD extension is required for image uploads.');
        }

        if (! in_array($format, ['png', 'jpeg'], true)) {
            throw new InvalidArgumentException('Unsupported output format.');
        }

        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);

        if ($source === false) {
            throw new InvalidArgumentException('The image could not be processed. Use a standard JPG, PNG or WEBP file.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);

        if ($format === 'png') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        } else {
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        if ($format === 'png') {
            imagepng($target, null, 6);
        } else {
            imagejpeg($target, null, 85);
        }
        $bytes = (string) ob_get_clean();

        if ($bytes === '') {
            throw new RuntimeException('Image encoding failed.');
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.'.($format === 'png' ? 'png' : 'jpg');
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path && str_starts_with($path, 'games/') && ! str_contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }
}