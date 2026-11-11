<?php

namespace App\Http\Controllers\Signing;

use App\Http\Controllers\Controller;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves signing request files (private disk) to whoever may see the request
 * (signing.view, or signing.create for their own): the original attachments and
 * the signed PDF from DocSign.
 */
class SigningFileController extends Controller
{
    public function original(SigningRequestFile $file): StreamedResponse
    {
        abort_unless(Auth::user() && $file->signingRequest->isVisibleTo(Auth::user()), 403);

        return $this->stream($file->disk, $file->path, $file->name);
    }

    public function signed(SigningRequest $signingRequest): StreamedResponse
    {
        abort_unless(Auth::user() && $signingRequest->isVisibleTo(Auth::user()), 403);
        abort_if($signingRequest->signed_path === null, 404);

        return $this->stream($signingRequest->signed_disk ?? 'local', $signingRequest->signed_path, str($signingRequest->name)->slug().'-signerad.pdf');
    }

    private function stream(string $disk, string $path, string $filename): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return response()->stream(function () use ($disk, $path) {
            $stream = Storage::disk($disk)->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
            'Content-Length' => Storage::disk($disk)->size($path),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
