<?php

namespace App\Http\Controllers;

use App\Models\Accounting\ExpenseClaim;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves expense-claim receipt files (private disk) with permission checks.
 *
 * The claim owner may view/download their own receipt.
 * Anyone with accounting.manage may view/download any receipt.
 */
class ExpenseClaimReceiptController extends Controller
{
    public function inline(ExpenseClaim $claim): StreamedResponse
    {
        $this->authorize($claim);

        abort_unless(Storage::disk($claim->receipt_disk)->exists($claim->receipt_path), 404);

        $size = Storage::disk($claim->receipt_disk)->size($claim->receipt_path);

        return response()->stream(function () use ($claim) {
            $stream = Storage::disk($claim->receipt_disk)->readStream($claim->receipt_path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type'        => $claim->receipt_mime_type,
            'Content-Disposition' => 'inline; filename="' . basename($claim->receipt_path) . '"',
            'Content-Length'      => $size,
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    public function download(ExpenseClaim $claim): StreamedResponse
    {
        $this->authorize($claim);

        abort_unless(Storage::disk($claim->receipt_disk)->exists($claim->receipt_path), 404);

        return response()->streamDownload(function () use ($claim) {
            $stream = Storage::disk($claim->receipt_disk)->readStream($claim->receipt_path);
            fpassthru($stream);
            fclose($stream);
        }, $claim->receipt_original_name, [
            'Content-Type'  => $claim->receipt_mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorize(ExpenseClaim $claim): void
    {
        $user = auth()->user();

        $isOwner    = $user?->member?->id === $claim->member_id;
        $isAccounting = $user?->canAny(['accounting.view', 'accounting.manage']);

        abort_unless($isOwner || $isAccounting, 403);
    }
}
