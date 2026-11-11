<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Vendor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Extracts a ZIP of vendor invoice files (PDF/image), reads each locally
 * (pdftotext + regex — no external AI call), and attempts to match an
 * existing Vendor by organisation number, falling back to name.
 *
 * Image files (JPEG/PNG/GIF/WEBP) cannot be read locally — this server has no
 * OCR engine installed — so they're attached with no suggested fields and
 * left for manual entry in the review step.
 *
 * Extracted files are written to a scratch disk location (not yet attached to
 * any Invoice) so a caller can review/adjust before committing — see the
 * "Importera ZIP" admin page and InvoiceController::importZip(), which move
 * confirmed files into permanent AccountingDocument storage.
 */
class InvoiceZipImporter
{
    private const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
        'image/gif'        => 'gif',
        'image/webp'       => 'webp',
    ];

    private const MAX_FILES     = 60;
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20MB, matches receipt-scan
    private const SCRATCH_DIR   = 'accounting/zip-import';
    private const PDFTOTEXT     = '/usr/bin/pdftotext';

    /**
     * @return array{
     *   batch_id: string,
     *   candidates: list<array{
     *     scratch_path: string, original_name: string, mime_type: string, size_bytes: int,
     *     date: string|null, vendor_name: string|null, matched_vendor_id: int|null,
     *     amount_ore: int|null, description: string|null,
     *     suggested_account_id: int|null, suggested_account_code: string|null,
     *     confidence: string|null, error: string|null,
     *   }>,
     *   skipped: list<array{original_name: string, reason: string}>,
     * }
     */
    public function import(string $zipPath): array
    {
        $batchId = (string) Str::uuid();
        $zip     = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Kunde inte öppna zip-filen. Kontrollera att den inte är trasig.');
        }

        // pdftotext is fast but still a subprocess per file — the web server's
        // default max_execution_time (30s) can be tight for a full 60-file batch.
        // Only ever *raise* the limit: CLI/cron runs are unlimited (0), and a small
        // zip would otherwise impose a 33-second limit on the whole process.
        $needed  = min($zip->numFiles, self::MAX_FILES) * 3 + 30;
        $current = (int) ini_get('max_execution_time');
        if ($current !== 0 && $current < $needed) {
            set_time_limit($needed);
        }

        $candidates = [];
        $skipped    = [];
        $processed  = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip directory entries and macOS metadata cruft
            if (str_ends_with($name, '/') || str_contains($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }

            if ($processed >= self::MAX_FILES) {
                $skipped[] = ['original_name' => $name, 'reason' => 'Max ' . self::MAX_FILES . ' filer per import — resten hoppades över'];
                continue;
            }

            $stat = $zip->statIndex($i);
            if ($stat && $stat['size'] > self::MAX_FILE_SIZE) {
                $skipped[] = ['original_name' => $name, 'reason' => 'Filen är större än 20 MB'];
                continue;
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false || $contents === '') {
                $skipped[] = ['original_name' => $name, 'reason' => 'Kunde inte läsa filen ur zip:en'];
                continue;
            }

            $mime = $this->detectMime($contents, $name);
            if (! isset(self::ALLOWED_MIMES[$mime])) {
                $skipped[] = ['original_name' => $name, 'reason' => 'Filtyp stöds inte (endast PDF/JPEG/PNG/GIF/WEBP)'];
                continue;
            }

            $processed++;

            $scratchPath = self::SCRATCH_DIR . '/' . $batchId . '/' . Str::uuid() . '.' . self::ALLOWED_MIMES[$mime];
            Storage::disk('local')->put($scratchPath, $contents);

            $extracted = $mime === 'application/pdf'
                ? $this->extractFromPdf(Storage::disk('local')->path($scratchPath))
                : $this->noExtraction();

            $candidates[] = [
                'scratch_path'           => $scratchPath,
                'original_name'          => basename($name),
                'mime_type'              => $mime,
                'size_bytes'             => strlen($contents),
                'date'                   => $extracted['date'],
                'vendor_name'            => $extracted['vendor_name'],
                'matched_vendor_id'      => $extracted['matched_vendor_id'],
                'amount_ore'             => $extracted['amount_ore'],
                'description'            => $extracted['description'],
                'suggested_account_id'   => $extracted['suggested_account_id'],
                'suggested_account_code' => null,
                'confidence'             => $extracted['confidence'],
                'error'                  => $extracted['error'],
            ];
        }

        $zip->close();

        return ['batch_id' => $batchId, 'candidates' => $candidates, 'skipped' => $skipped];
    }

    /** Discard a batch's scratch files (e.g. the review was cancelled). */
    public function discardBatch(string $batchId): void
    {
        Storage::disk('local')->deleteDirectory(self::SCRATCH_DIR . '/' . $batchId);
    }

    // ── Local extraction (PDF only) ──────────────────────────────────────────

    /** @return array{date: string|null, vendor_name: string|null, matched_vendor_id: int|null, amount_ore: int|null, description: string|null, suggested_account_id: int|null, confidence: string|null, error: string|null} */
    private function extractFromPdf(string $absolutePath): array
    {
        $text = $this->pdfToText($absolutePath);

        if ($text === null) {
            return $this->noExtraction('Kunde inte läsa text ur PDF:en (pdftotext saknas eller filen är en skannad bild utan textlager).');
        }

        $orgNumber = $this->findOrgNumber($text);
        $vendor    = $orgNumber ? Vendor::active()->where('org_number', $orgNumber)->first() : null;
        $vendorName = $vendor?->name ?? $this->guessVendorName($text);
        $amountOre  = $this->findAmount($text);
        $date       = $this->findDate($text);

        $confidence = match (true) {
            $vendor !== null && $amountOre !== null => 'high',
            $amountOre !== null                     => 'medium',
            default                                  => 'low',
        };

        return [
            'date'                 => $date,
            'vendor_name'          => $vendorName,
            'matched_vendor_id'    => $vendor?->id,
            'amount_ore'           => $amountOre,
            'description'          => null,
            'suggested_account_id' => $vendor?->default_account_id,
            'confidence'           => $confidence,
            'error'                => null,
        ];
    }

    private function noExtraction(?string $error = null): array
    {
        return [
            'date' => null, 'vendor_name' => null, 'matched_vendor_id' => null,
            'amount_ore' => null, 'description' => null, 'suggested_account_id' => null,
            'confidence' => null, 'error' => $error,
        ];
    }

    private function pdfToText(string $absolutePath): ?string
    {
        if (! file_exists(self::PDFTOTEXT)) {
            return null;
        }

        $out = shell_exec(self::PDFTOTEXT . ' -layout ' . escapeshellarg($absolutePath) . ' -');

        return ($out !== null && trim($out) !== '') ? $out : null;
    }

    /** Swedish organisationsnummer, e.g. "556677-8899". */
    private function findOrgNumber(string $text): ?string
    {
        if (preg_match('/\b(\d{6}-\d{4})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Total amount, preferring an explicit "att betala" / "totalt" label over
     * a bare first-match — invoices list several amounts (ex. moms, moms, per
     * line item), and the labelled total is the one that matters for AP entry.
     */
    private function findAmount(string $text): ?int
    {
        $amount = '(\d{1,3}(?:[ .]\d{3})*,\d{2})\s*(?:kr|SEK)?';

        foreach ([
            '/att\s+betala\s*:?\s*' . $amount . '/iu',
            '/summa\s+att\s+betala\s*:?\s*' . $amount . '/iu',
            '/totalt\s+att\s+betala\s*:?\s*' . $amount . '/iu',
            '/fakturabelopp\s*:?\s*' . $amount . '/iu',
            '/(?:^|\n)\s*total(?:t|summa)?\s*:?\s*' . $amount . '/iu',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->parseSwedishAmount($m[1]);
            }
        }

        return null;
    }

    private function parseSwedishAmount(string $raw): int
    {
        $clean = str_replace([' ', '.'], '', trim($raw));
        $clean = str_replace(',', '.', $clean);

        return (int) round((float) $clean * 100);
    }

    private function findDate(string $text): ?string
    {
        if (preg_match('/fakturadatum\s*:?\s*(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
            return $m[1];
        }

        // Fall back to the first ISO-formatted date anywhere in the document
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Best-effort label only — not used for matching, just shown for manual review. */
    private function guessVendorName(string $text): ?string
    {
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (mb_strlen($line) >= 2 && mb_strlen($line) <= 60) {
                return $line;
            }
        }

        return null;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function detectMime(string $contents, string $name): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($contents) ?: '';

        // finfo sometimes reports generic octet-stream for well-formed files with
        // unusual headers — fall back to the zip entry's extension in that case.
        if (! isset(self::ALLOWED_MIMES[$mime])) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $byExt = array_flip(self::ALLOWED_MIMES)[$ext] ?? null;
            if ($byExt) {
                return $byExt;
            }
        }

        return $mime;
    }
}
