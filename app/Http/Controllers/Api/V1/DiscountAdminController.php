<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Services\DiscountCodePngService;
use App\Services\ScannerService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Picqer\Barcode\BarcodeGeneratorSVG;
use ZipArchive;

class DiscountAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['discounts.view', 'discounts.manage']), 403);

        $discounts = Discount::query()
            ->when($request->input('applies_to'), fn ($q, $v) => $q->where('applies_to', $v))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->withCount('codes', 'uses')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($discounts);
    }

    public function show(Request $request, Discount $discount): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['discounts.view', 'discounts.manage']), 403);

        $discount->load('codes');

        return response()->json(['data' => $discount]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('discounts.manage'), 403);

        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'type'                => 'required|in:percentage,fixed',
            'value'               => 'required|integer|min:0',
            'applies_to'          => 'required|in:membership,event,shop,all',
            'condition'           => 'nullable|in:first_membership',
            'min_subtotal'        => 'nullable|integer|min:0',
            'max_discount'        => 'nullable|integer|min:0',
            'max_pct_of_total'    => 'nullable|numeric|min:0|max:100',
            'valid_from'          => 'nullable|date',
            'valid_until'         => 'nullable|date',
            'max_uses'            => 'nullable|integer|min:1',
            'max_uses_per_user'   => 'nullable|integer|min:1',
            'requires_membership' => 'nullable|boolean',
            'is_active'           => 'boolean',
        ]);

        $data['is_active'] = $data['is_active'] ?? true;

        $discount = Discount::create($data);

        AuditLog::record('discount_created', 'shop', $discount->name, $discount);

        return response()->json(['data' => $discount], 201);
    }

    public function update(Request $request, Discount $discount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('discounts.manage'), 403);

        $data = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'type'                => 'sometimes|in:percentage,fixed',
            'value'               => 'sometimes|integer|min:0',
            'applies_to'          => 'sometimes|in:membership,event,shop,all',
            'valid_from'          => 'nullable|date',
            'valid_until'         => 'nullable|date',
            'max_uses'            => 'nullable|integer|min:1',
            'max_uses_per_user'   => 'nullable|integer|min:1',
            'is_active'           => 'boolean',
        ]);

        $discount->update($data);

        AuditLog::record('discount_updated', 'shop', $discount->name, $discount);

        return response()->json(['data' => $discount]);
    }

    public function destroy(Request $request, Discount $discount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('discounts.manage'), 403);

        $name = $discount->name;
        $discount->delete();

        AuditLog::record('discount_deleted', 'shop', $name, null);

        return response()->json(['message' => 'Discount deleted.']);
    }

    // ── Codes ─────────────────────────────────────────────────────────────────

    public function storeCode(Request $request, Discount $discount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('discounts.manage'), 403);

        $data = $request->validate([
            'code'          => 'required|string|max:50|unique:discount_codes,code',
            'is_single_use' => 'boolean',
            'valid_from'    => 'nullable|date',
            'valid_until'   => 'nullable|date',
        ]);

        $code = $discount->codes()->create($data);

        AuditLog::record('discount_code_created', 'shop', $discount->name . ': ' . $code->code, $discount);

        return response()->json(['data' => $code], 201);
    }

    public function destroyCode(Request $request, Discount $discount, DiscountCode $code): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('discounts.manage'), 403);
        abort_unless($code->discount_id === $discount->id, 404);

        $codeValue = $code->code;
        $code->delete();

        AuditLog::record('discount_code_deleted', 'shop', $discount->name . ': ' . $codeValue, null);

        return response()->json(['message' => 'Discount code deleted.']);
    }

    // ── Downloads ─────────────────────────────────────────────────────────────

    public function downloadCodePng(Request $request, Discount $discount, DiscountCode $code): Response
    {
        abort_unless($request->user()->hasPermissionTo('payments.view'), 403);
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
        abort_unless($request->user()->hasPermissionTo('payments.view'), 403);

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
