<?php

namespace App\Services;

use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Picqer\Barcode\BarcodeGeneratorPNG;

class DiscountCodePngService
{
    private const W        = 400;
    private const PAD      = 20;
    private const QR_TARGET = 180;  // rendered size of QR including quiet zone
    private const QR_QUIET  = 4;    // quiet-zone modules each side
    private const BAR_H    = 36;
    private const LOGO_MAX_W = 280;
    private const LOGO_MAX_H = 54;

    private string $fontR;
    private string $fontMono;

    public function __construct()
    {
        $base          = resource_path('fonts');
        $this->fontR    = $base . '/Ubuntu-R.ttf';
        $this->fontMono = $base . '/UbuntuMono-B.ttf';
    }

    /**
     * Render a single discount code as a PNG and return the raw bytes.
     *
     * @param  string  $qrContent  URL or raw code to encode in the QR
     * @param  bool    $hasCta     Whether qrContent is a member CTA URL (vs raw code)
     */
    public function generate(
        DiscountCode $code,
        Discount $discount,
        string $qrContent,
        string $targetLabel,
        bool $hasCta,
    ): string {
        $contentW = self::W - 2 * self::PAD;
        $isRedeemed = $code->isUsedUp();

        // --- QR matrix ---
        $result  = Encoder::encode($qrContent, ErrorCorrectionLevel::M());
        $matrix  = $result->getMatrix();
        $mSize   = $matrix->getWidth();
        $modules = $mSize + 2 * self::QR_QUIET;
        $modPx   = (int) floor(self::QR_TARGET / $modules);
        $qrPx    = $modules * $modPx;

        // --- Barcode PNG ---
        $barGen  = new BarcodeGeneratorPNG();
        $barPng  = $barGen->getBarcode('DIS-' . $code->code, BarcodeGeneratorPNG::TYPE_CODE_128, 1, self::BAR_H);
        $barSrc  = imagecreatefromstring($barPng);
        $barSrcW = imagesx($barSrc);

        // --- Logo ---
        $logoImg   = null;
        $logoDrawW = 0;
        $logoDrawH = 0;
        if ($discount->logo !== Discount::LOGO_NONE) {
            $logoFile = $discount->logo === Discount::LOGO_BSK
                ? public_path('bsk-logo.png')
                : public_path('armagedon-logo.png');
            if (file_exists($logoFile)) {
                $logoSrc   = imagecreatefrompng($logoFile);
                $logoSrcW  = imagesx($logoSrc);
                $logoSrcH  = imagesy($logoSrc);
                $scale     = min(self::LOGO_MAX_W / $logoSrcW, self::LOGO_MAX_H / $logoSrcH);
                $logoDrawW = (int) round($logoSrcW * $scale);
                $logoDrawH = (int) round($logoSrcH * $scale);
                $logoImg   = $logoSrc;
            }
        }

        // --- Compute card height ---
        $h = self::PAD;
        if ($logoImg) {
            $h += $logoDrawH + 14;
        }
        $h += $qrPx + 8;
        $h += $this->lineH($this->fontR, 9) + 10;  // hint text
        $h += 1 + 12;                                // divider + gap
        $h += $this->lineH($this->fontMono, 14) + 6; // code
        $h += $this->lineH($this->fontR, 10) + 4;   // target label
        if ($code->batch_label) {
            $h += $this->lineH($this->fontR, 9) + 4;
        }
        $h += 6;           // gap before divider
        $h += 1 + 10;      // divider + gap
        $h += self::BAR_H;
        if ($isRedeemed) {
            $h += 6 + $this->lineH($this->fontR, 9);
        }
        $h += self::PAD;

        // --- Create canvas ---
        $img = imagecreatetruecolor(self::W, $h);
        imagealphablending($img, true);

        $white  = imagecolorallocate($img, 255, 255, 255);
        $black  = imagecolorallocate($img, 26,  26,  26);
        $grayHint = imagecolorallocate($img, 120, 120, 120);
        $grayBatch = imagecolorallocate($img, 170, 170, 170);
        $grayDiv  = imagecolorallocate($img, 210, 210, 210);
        $grayBorder = imagecolorallocate($img, 224, 224, 224);

        imagefilledrectangle($img, 0, 0, self::W - 1, $h - 1, $white);
        imagerectangle($img, 0, 0, self::W - 1, $h - 1, $grayBorder);
        imagerectangle($img, 1, 1, self::W - 2, $h - 2, $grayBorder);

        // --- Logo ---
        $y = self::PAD;
        if ($logoImg) {
            $lx = (int) round((self::W - $logoDrawW) / 2);
            imagecopyresampled(
                $img, $logoImg,
                $lx, $y,
                0, 0,
                $logoDrawW, $logoDrawH,
                imagesx($logoImg), imagesy($logoImg),
            );
            imagedestroy($logoImg);
            $y += $logoDrawH + 14;
        }

        // --- QR code ---
        $qrX = (int) round((self::W - $qrPx) / 2);
        imagefilledrectangle($img, $qrX, $y, $qrX + $qrPx - 1, $y + $qrPx - 1, $white);
        for ($row = 0; $row < $mSize; $row++) {
            for ($col = 0; $col < $mSize; $col++) {
                if ($matrix->get($col, $row) === 1) {
                    $px = $qrX + ($col + self::QR_QUIET) * $modPx;
                    $py = $y   + ($row + self::QR_QUIET) * $modPx;
                    imagefilledrectangle($img, $px, $py, $px + $modPx - 1, $py + $modPx - 1, $black);
                }
            }
        }
        $y += $qrPx + 8;

        // --- Hint text ---
        $hint = $hasCta ? 'Skanna med mobilen för att registrera dig' : 'Skanna vid skanningsstationen';
        $y    = $this->drawCentered($img, $this->fontR, 9, $grayHint, $y, $hint);
        $y   += 10;

        // --- Divider ---
        $this->dashedLine($img, self::PAD, $y, self::W - self::PAD, $grayDiv);
        $y += 1 + 12;

        // --- Code text ---
        $y = $this->drawCentered($img, $this->fontMono, 14, $black, $y, $code->code);
        $y += 6;

        // --- Target label ---
        $y = $this->drawCentered($img, $this->fontR, 10, $grayHint, $y, $targetLabel);
        $y += 4;

        if ($code->batch_label) {
            $y = $this->drawCentered($img, $this->fontR, 9, $grayBatch, $y, $code->batch_label);
            $y += 4;
        }

        $y += 6;

        // --- Divider ---
        $this->dashedLine($img, self::PAD, $y, self::W - self::PAD, $grayDiv);
        $y += 1 + 10;

        // --- Barcode ---
        imagecopyresampled(
            $img, $barSrc,
            self::PAD, $y,
            0, 0,
            $contentW, self::BAR_H,
            $barSrcW, self::BAR_H,
        );
        imagedestroy($barSrc);
        $y += self::BAR_H;

        // --- Redeemed badge ---
        if ($isRedeemed) {
            $y += 6;
            $text = 'Inlöst ' . $code->redeemed_at->format('d/m Y');
            $this->drawCentered($img, $this->fontR, 9, $grayBatch, $y, $text);
        }

        // --- Capture PNG bytes ---
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    private function drawCentered(\GdImage $img, string $font, float $size, int $color, int $topY, string $text): int
    {
        $bbox   = imagettfbbox($size, 0, $font, $text);
        $textW  = abs($bbox[4] - $bbox[0]);
        $ascent = -$bbox[7];         // bbox[7] is negative (above baseline)
        $descent = $bbox[1];
        $x = (int) round((self::W - $textW) / 2);
        imagettftext($img, $size, 0, $x, $topY + $ascent, $color, $font, $text);
        return $topY + $ascent + $descent;
    }

    private function lineH(string $font, float $size): int
    {
        $bbox = imagettfbbox($size, 0, $font, 'Ag');
        return (int) abs($bbox[5] - $bbox[1]);
    }

    private function dashedLine(\GdImage $img, int $x1, int $y, int $x2, int $color): void
    {
        $on = true;
        $x  = $x1;
        while ($x <= $x2) {
            $end = min($x + 3, $x2);
            if ($on) {
                imageline($img, $x, $y, $end, $y, $color);
            }
            $x  = $end + 1;
            $on = !$on;
        }
    }
}
