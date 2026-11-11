<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

class QrPng
{
    public static function render(string $content, int $modulePx = 6, int $quiet = 4): string
    {
        $matrix  = Encoder::encode($content, ErrorCorrectionLevel::M())->getMatrix();
        $mSize   = $matrix->getWidth();
        $modules = $mSize + 2 * $quiet;
        $px      = $modules * $modulePx;

        $img   = imagecreatetruecolor($px, $px);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $px - 1, $px - 1, $white);

        for ($row = 0; $row < $mSize; $row++) {
            for ($col = 0; $col < $mSize; $col++) {
                if ($matrix->get($col, $row) === 1) {
                    $x = ($col + $quiet) * $modulePx;
                    $y = ($row + $quiet) * $modulePx;
                    imagefilledrectangle($img, $x, $y, $x + $modulePx - 1, $y + $modulePx - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return $png;
    }
}
