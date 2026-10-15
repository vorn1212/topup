<?php

namespace Tests\Unit;

use App\Support\QrImage;
use PHPUnit\Framework\TestCase;

class QrImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The GD extension is not available.');
        }
    }

    public function test_trim_removes_white_margins_and_keeps_a_border(): void
    {
        $path = sys_get_temp_dir().'/qr-trim-'.uniqid().'.png';

        // 800x800 white image with a 200x240 dark block in the middle.
        $img = imagecreatetruecolor(800, 800);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 300, 280, 499, 519, imagecolorallocate($img, 20, 20, 20));
        imagepng($img, $path);

        $this->assertTrue(QrImage::trim($path));

        [$width, $height] = getimagesize($path);

        // Content is 200x240 plus about 5% border on each side (plus a 2px safety edge).
        $this->assertLessThan(320, $width);
        $this->assertLessThan(360, $height);
        $this->assertGreaterThan(200, $width);
        $this->assertGreaterThan(240, $height);

        @unlink($path);
    }

    public function test_trim_ignores_a_missing_or_blank_file(): void
    {
        $this->assertFalse(QrImage::trim(sys_get_temp_dir().'/does-not-exist.png'));

        $path = sys_get_temp_dir().'/qr-blank-'.uniqid().'.png';
        $img = imagecreatetruecolor(300, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagepng($img, $path);

        $this->assertFalse(QrImage::trim($path));

        @unlink($path);
    }
}