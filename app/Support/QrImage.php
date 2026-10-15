<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class QrImage
{
    /**
     * Crop empty white margins around a PNG and add a small even white border,
     * so the QR fills as much of the screen as possible. Returns false (and
     * leaves the file untouched) when it cannot be processed.
     */
    public static function trim(string $path): bool
    {
        if (! function_exists('imagecreatefrompng') || ! is_file($path)) {
            return false;
        }

        try {
            $source = @imagecreatefrompng($path);
            if ($source === false) {
                return false;
            }

            $width = imagesx($source);
            $height = imagesy($source);

            // Flatten onto white so transparent areas count as white.
            $flat = imagecreatetruecolor($width, $height);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $source, 0, 0, 0, 0, $width, $height);

            $minX = $width;
            $minY = $height;
            $maxX = -1;
            $maxY = -1;

            for ($y = 0; $y < $height; $y += 2) {
                for ($x = 0; $x < $width; $x += 2) {
                    $rgb = imagecolorat($flat, $x, $y);
                    $r = ($rgb >> 16) & 255;
                    $g = ($rgb >> 8) & 255;
                    $b = $rgb & 255;

                    // Anything clearly not white (card, text, QR modules).
                    if (min($r, $g, $b) < 215) {
                        $minX = min($minX, $x);
                        $minY = min($minY, $y);
                        $maxX = max($maxX, $x);
                        $maxY = max($maxY, $y);
                    }
                }
            }

            if ($maxX < 0) {
                return false; // blank image: leave it alone
            }

            $minX = max(0, $minX - 2);
            $minY = max(0, $minY - 2);
            $maxX = min($width - 1, $maxX + 2);
            $maxY = min($height - 1, $maxY + 2);

            $boxWidth = $maxX - $minX + 1;
            $boxHeight = $maxY - $minY + 1;

            // Safety: never keep something too small to be a QR code.
            if ($boxWidth < 120 || $boxHeight < 120) {
                return false;
            }

            $pad = (int) round(max($boxWidth, $boxHeight) * 0.05);

            $out = imagecreatetruecolor($boxWidth + 2 * $pad, $boxHeight + 2 * $pad);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
            imagecopy($out, $flat, $pad, $pad, $minX, $minY, $boxWidth, $boxHeight);

            return imagepng($out, $path, 6);
        } catch (Throwable $e) {
            Log::warning('QR image trim failed', ['message' => $e->getMessage()]);

            return false;
        }
    }
}