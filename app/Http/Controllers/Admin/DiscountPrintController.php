<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Services\DiscountCodePngService;
use App\Services\ScannerService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Picqer\Barcode\BarcodeGeneratorSVG;
use ZipArchive;

class DiscountPrintController extends Controller
{
    public function printCodes(Request $request, Discount $discount): \Illuminate\View\View
    {
        abort_unless($request->user()?->hasPermissionTo('payments.view'), 403);

        $barGenerator = new BarcodeGeneratorSVG();
        $qrWriter     = new Writer(new ImageRenderer(new RendererStyle(180), new SvgImageBackEnd()));
        $scanner      = app(ScannerService::class);

        $query = DiscountCode::where('discount_id', $discount->id);

        if ($request->filled('batch')) {
            $query->where('batch_label', $request->input('batch') === '__none__' ? null : $request->input('batch'));
        }

        if ($request->boolean('available_only')) {
            $query->available();
        }

        $codes = $query->orderBy('batch_label')->orderBy('code')->get();

        $codesWithBarcodes = $codes->map(function ($code) use ($barGenerator, $qrWriter, $scanner, $discount) {
            $ctaUrl = $scanner->ctaUrl($discount, $code->code);

            return [
                'model'   => $code,
                'barcode' => $barGenerator->getBarcode('DIS-' . $code->code, BarcodeGeneratorSVG::TYPE_CODE_128, 2, 40),
                'qr'      => $qrWriter->writeString($ctaUrl ?? 'DIS-' . $code->code),
                'ctaUrl'  => $ctaUrl,
                'target'  => $scanner->targetLabel($discount),
            ];
        });

        return view('admin.discounts.print-codes', compact('discount', 'codesWithBarcodes'));
    }

    public function downloadCodePng(Request $request, Discount $discount, DiscountCode $code): Response
    {
        abort_unless($request->user()?->hasPermissionTo('payments.view'), 403);
        abort_unless($code->discount_id === $discount->id, 404);

        $scanner     = app(ScannerService::class);
        $ctaUrl      = $scanner->ctaUrl($discount, $code->code);
        $targetLabel = $scanner->targetLabel($discount);

        $png = app(DiscountCodePngService::class)->generate(
            code: $code,
            discount: $discount,
            qrContent: $ctaUrl ?? 'DIS-' . $code->code,
            targetLabel: $targetLabel,
            hasCta: $ctaUrl !== null,
        );

        $filename = 'kod-' . strtolower($code->code) . '.png';

        return response($png, 200, [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($png),
        ]);
    }

    public function downloadCodesZip(Request $request, Discount $discount): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()?->hasPermissionTo('payments.view'), 403);

        $scanner = app(ScannerService::class);
        $pngSvc  = app(DiscountCodePngService::class);

        $query = DiscountCode::where('discount_id', $discount->id);

        if ($request->filled('batch')) {
            $query->where('batch_label', $request->input('batch') === '__none__' ? null : $request->input('batch'));
        }

        if ($request->boolean('available_only')) {
            $query->available();
        }

        $codes       = $query->orderBy('batch_label')->orderBy('code')->get();
        $targetLabel = $scanner->targetLabel($discount);

        $zipPath = tempnam(sys_get_temp_dir(), 'discount-codes-');
        $zip     = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        foreach ($codes as $code) {
            $ctaUrl = $scanner->ctaUrl($discount, $code->code);
            $png    = $pngSvc->generate(
                code: $code,
                discount: $discount,
                qrContent: $ctaUrl ?? 'DIS-' . $code->code,
                targetLabel: $targetLabel,
                hasCta: $ctaUrl !== null,
            );
            $zip->addFromString('kod-' . strtolower($code->code) . '.png', $png);
        }

        $zip->close();

        $slugName = \Str::slug($discount->name);
        $filename = 'rabattkoder-' . $slugName . '.zip';

        return response()->streamDownload(function () use ($zipPath) {
            readfile($zipPath);
            unlink($zipPath);
        }, $filename, ['Content-Type' => 'application/zip']);
    }
}
