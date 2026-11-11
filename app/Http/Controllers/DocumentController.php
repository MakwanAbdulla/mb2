<?php

namespace App\Http\Controllers;

use App\Models\Accounting\AccountingDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private accounting documents with permission checks.
 *
 * Every file — vendor invoice PDFs, receipts, contracts, attachments — lives
 * on the 'local' (private) disk and must be served through here. No public
 * storage URLs are ever used for accounting files.
 *
 * Paths use UUIDs as filenames:  accounting/documents/{year}/{uuid}.{ext}
 */
class DocumentController extends Controller
{
    public function inline(AccountingDocument $document): StreamedResponse
    {
        $this->requireAccounting();

        return $this->streamInline(
            (string) $document->disk,
            (string) $document->path,
            (string) $document->mime_type,
        );
    }

    public function download(AccountingDocument $document): StreamedResponse
    {
        $this->requireAccounting();

        return $this->streamDownload(
            (string) $document->disk,
            (string) $document->path,
            (string) $document->original_name,
            (string) $document->mime_type,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requireAccounting(): void
    {
        abort_unless(
            auth()->user()?->canAny(['accounting.view', 'accounting.manage']),
            403
        );
    }

    private function streamInline(string $disk, string $path, string $mime): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $size = Storage::disk($disk)->size($path);

        return response()->stream(function () use ($disk, $path) {
            $stream = Storage::disk($disk)->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Content-Length'      => $size,
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    private function streamDownload(string $disk, string $path, string $filename, string $mime): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return response()->streamDownload(function () use ($disk, $path) {
            $stream = Storage::disk($disk)->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, $filename, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
