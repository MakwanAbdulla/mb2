<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extracts structured data from PDF attachments using `pdftotext`.
 *
 * Strategy (hybrid):
 *   1. Extract raw text with pdftotext -layout
 *   2. Try regex extraction for known document types (SumUp)
 *   3. If the key amount (net_amount_ore) is still missing, fall back to
 *      the Anthropic API (Claude Haiku) — only when ANTHROPIC_API_KEY is set
 *
 * Regex covers:
 *   - betalningsrapport  (daily/monthly payment report — gross, fees, net payout)
 *   - utbetalningsrapport (payout confirmation — same summary structure)
 *   - faktura            (SumUp invoice — invoice number, period, total)
 *
 * Claude fallback covers any other provider or changed SumUp format.
 *
 * Amount format in SumUp PDFs: English thousands separator, dot decimal, "kr" suffix.
 *   Example: "8,584.79 kr" → 858 479 öre
 *
 * Returns an associative array with keys matching AccountingInboxItem fillable fields:
 *   extracted_doc_type, extracted_doc_number, extracted_period_start,
 *   extracted_period_end, extracted_net_amount_ore, extracted_gross_amount_ore,
 *   extracted_fee_amount_ore, extracted_text
 *
 * Returns an empty array when pdftotext is unavailable or extraction fails entirely.
 */
class PdfDataExtractor
{
    private const PDFTOTEXT        = '/usr/bin/pdftotext';
    private const ANTHROPIC_URL    = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Extract data from the PDF at the given Storage-relative path.
     *
     * @param  string      $storagePath   Path relative to the default Storage disk
     * @param  string|null $originalName  Original filename for fallback period extraction
     * @return array<string, mixed>
     */
    public function extract(string $storagePath, ?string $originalName = null): array
    {
        $text = $this->extractText($storagePath);

        if ($text === null) {
            return [];
        }

        $truncated = mb_substr($text, 0, 8000);
        $docType   = $this->detectDocType($text);

        $base = ['extracted_text' => $truncated, 'extracted_doc_type' => $docType];

        // ── Step 1: regex extraction ──────────────────────────────────────────
        $fields = match ($docType) {
            'betalningsrapport'   => [...$base, ...$this->extractReportFields($text)],
            'utbetalningsrapport' => [...$base, ...$this->extractReportFields($text)],
            'faktura'             => [...$base, ...$this->extractInvoiceFields($text)],
            default               => $base,
        };

        // Filename fallback for period (e.g. "20260414-" or "2026-03-")
        if (empty($fields['extracted_period_start']) && $originalName !== null) {
            $periodFromName = $this->extractPeriodFromFilename($originalName);
            if ($periodFromName) {
                $fields = [...$periodFromName, ...$fields];
            }
        }

        // ── Step 2: Claude fallback if amount is still missing ────────────────
        if (($fields['extracted_net_amount_ore'] ?? null) === null) {
            $fromClaude = $this->extractViaClaude($truncated);
            if ($fromClaude) {
                // Claude fills in what regex missed; regex values take precedence
                $fields = [...$fromClaude, ...$fields];
                // Preserve the full extracted text
                $fields['extracted_text'] = $truncated;
            }
        }

        return $fields;
    }

    // ── Private: text extraction ──────────────────────────────────────────────

    /**
     * Run pdftotext and return the raw text output, or null on failure.
     */
    private function extractText(string $storagePath): ?string
    {
        if (! file_exists(self::PDFTOTEXT)) {
            return null;
        }

        $absolutePath = Storage::path($storagePath);

        if (! file_exists($absolutePath)) {
            return null;
        }

        $cmd    = self::PDFTOTEXT . ' -layout ' . escapeshellarg($absolutePath) . ' -';
        $output = shell_exec($cmd);

        return ($output !== null && $output !== '') ? $output : null;
    }

    // ── Private: regex extraction ─────────────────────────────────────────────

    /**
     * Detect SumUp document type from keyword presence.
     */
    private function detectDocType(string $text): ?string
    {
        // Order matters: Utbetalningsrapport contains "rapport" so check it first
        if (stripos($text, 'Utbetalningsrapport') !== false) {
            return 'utbetalningsrapport';
        }
        if (stripos($text, 'Betalningsrapport') !== false) {
            return 'betalningsrapport';
        }
        if (stripos($text, 'Fakturanummer') !== false) {
            return 'faktura';
        }

        return null;
    }

    /**
     * Extract fields from a Betalningsrapport or Utbetalningsrapport.
     *
     * SumUp layout (one line per column header, then one data line):
     *   Brutto    Avgifter    Avdrag    Utbetalat
     *   3,030.00 kr  44.05 kr  0.00 kr  2,904.95 kr
     *
     * We find the first line that contains ≥4 amounts with "kr" to get
     * gross, fees, deductions, and net payout.
     */
    private function extractReportFields(string $text): array
    {
        $result = [];

        // Period: look for "Redovisningsperiod: YYYY-MM-DD - YYYY-MM-DD"
        //         or "Period: YYYY-MM-DD - YYYY-MM-DD"
        if (preg_match(
            '/(?:Redovisningsperiod|Period)\s*:\s*(\d{4}-\d{2}-\d{2})\s*[-–]\s*(\d{4}-\d{2}-\d{2})/iu',
            $text,
            $m
        )) {
            $result['extracted_period_start'] = $m[1];
            $result['extracted_period_end']   = $m[2];
        }

        // Summary row: first line with ≥4 amounts ending in "kr"
        foreach (explode("\n", $text) as $line) {
            preg_match_all('/(\d{1,3}(?:,\d{3})*\.\d{2})\s*kr\b/i', $line, $matches);
            if (\count($matches[1]) >= 4) {
                $result['extracted_gross_amount_ore'] = $this->parseToOre($matches[1][0]);
                $result['extracted_fee_amount_ore']   = $this->parseToOre($matches[1][1]);
                // Index 2 is deductions (avdrag) — not stored separately
                $result['extracted_net_amount_ore']   = $this->parseToOre($matches[1][3]);
                break;
            }
        }

        return $result;
    }

    /**
     * Extract fields from a SumUp Faktura (invoice).
     *
     * Lines of interest:
     *   Fakturanummer: MRNCVH66-202603
     *   Period: 2026-03-01 - 2026-03-31
     *   Summa  130.21 kr          (or "Summa: 130.21 kr")
     */
    private function extractInvoiceFields(string $text): array
    {
        $result = [];

        // Invoice number
        if (preg_match('/Fakturanummer\s*:\s*([A-Z0-9\-]+)/iu', $text, $m)) {
            $result['extracted_doc_number'] = trim($m[1]);
        }

        // Period
        if (preg_match(
            '/Period\s*:\s*(\d{4}-\d{2}-\d{2})\s*[-–]\s*(\d{4}-\d{2}-\d{2})/iu',
            $text,
            $m
        )) {
            $result['extracted_period_start'] = $m[1];
            $result['extracted_period_end']   = $m[2];
        }

        // Total: "Summa 130.21 kr" or "Summa: 130.21 kr"
        if (preg_match('/\bSumma\s*:?\s*(\d{1,3}(?:,\d{3})*\.\d{2})\s*kr\b/iu', $text, $m)) {
            $result['extracted_net_amount_ore'] = $this->parseToOre($m[1]);
        }

        return $result;
    }

    /**
     * Try to extract a period from a filename.
     *
     * Two formats recognised at the start of the basename:
     *   YYYYMMDD  →  single-day period  (e.g. "20260414-daily-report.pdf")
     *   YYYY-MM   →  full-month period  (e.g. "2026-03-monthly-fee-invoice.pdf")
     */
    private function extractPeriodFromFilename(string $filename): array
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // YYYYMMDD prefix
        if (preg_match('/^(\d{4})(\d{2})(\d{2})\b/', $basename, $m)) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            $day   = (int) $m[3];

            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                $date = \sprintf('%04d-%02d-%02d', $year, $month, $day);
                return ['extracted_period_start' => $date, 'extracted_period_end' => $date];
            }
        }

        // YYYY-MM prefix
        if (preg_match('/^(\d{4})-(\d{2})\b/', $basename, $m)) {
            $year  = (int) $m[1];
            $month = (int) $m[2];

            if ($month >= 1 && $month <= 12) {
                $start = \sprintf('%04d-%02d-01', $year, $month);
                $end   = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
                return ['extracted_period_start' => $start, 'extracted_period_end' => $end];
            }
        }

        return [];
    }

    /**
     * Parse a SumUp amount string to öre.
     *
     * Examples:
     *   "8,584.79"  → 858479
     *   "130.21"    → 13021
     *   "0.00"      → 0
     *
     * Commas are thousands separators; the dot is the decimal separator.
     */
    private function parseToOre(string $amountStr): int
    {
        $clean = str_replace(',', '', trim($amountStr));
        return (int) round((float) $clean * 100);
    }

    // ── Private: Claude fallback ──────────────────────────────────────────────

    /**
     * Send the extracted text to Claude and parse the structured response.
     * Returns null when the API key is not configured, the call fails,
     * or the response cannot be parsed as valid JSON.
     *
     * @return array<string, mixed>|null
     */
    private function extractViaClaude(string $text): ?array
    {
        $apiKey = config('services.anthropic.key');

        if (! $apiKey) {
            return null;
        }

        $prompt = <<<PROMPT
Du är en bokföringsassistent. Extrahera fält från detta redovisningsdokument och returnera ENBART ett JSON-objekt, ingen annan text.

Schema (alla fält obligatoriska, sätt null om värdet inte finns):
{
  "doc_type":        "betalningsrapport" | "utbetalningsrapport" | "faktura" | null,
  "doc_number":      sträng eller null,
  "period_start":    "YYYY-MM-DD" eller null,
  "period_end":      "YYYY-MM-DD" eller null,
  "net_amount_ore":  heltal i öre (1 kr = 100 öre) eller null,
  "gross_amount_ore": heltal i öre eller null,
  "fee_amount_ore":  heltal i öre eller null
}

Regler:
- net_amount_ore = utbetalat/nettosumma efter avgifter, INTE brutto
- Ignorera tusentalsavgränsare (komma eller punkt beroende på format)
- Belopp kan stå som "1 234,56 kr" (sv) eller "1,234.56 kr" (en)

Dokument:
{$text}
PROMPT;

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                ])
                ->post(self::ANTHROPIC_URL, [
                    'model'      => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 512,
                    'messages'   => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('PdfDataExtractor: Anthropic API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $raw = $response->json('content.0.text', '');
            // Strip potential markdown code fences
            $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $raw = preg_replace('/\s*```$/', '', $raw);

            $data = json_decode($raw, true);

            if (! is_array($data)) {
                Log::warning('PdfDataExtractor: could not parse Claude response as JSON', compact('raw'));
                return null;
            }

            return $this->normaliseClaude($data);
        } catch (\Throwable $e) {
            Log::warning('PdfDataExtractor: Claude call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Map Claude's JSON keys to AccountingInboxItem field names and validate types.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normaliseClaude(array $data): array
    {
        $result = [];

        if (isset($data['doc_type']) && is_string($data['doc_type'])) {
            $result['extracted_doc_type'] = $data['doc_type'];
        }

        if (isset($data['doc_number']) && is_string($data['doc_number'])) {
            $result['extracted_doc_number'] = $data['doc_number'];
        }

        foreach (['period_start' => 'extracted_period_start', 'period_end' => 'extracted_period_end'] as $from => $to) {
            if (isset($data[$from]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data[$from])) {
                $result[$to] = $data[$from];
            }
        }

        foreach ([
            'net_amount_ore'   => 'extracted_net_amount_ore',
            'gross_amount_ore' => 'extracted_gross_amount_ore',
            'fee_amount_ore'   => 'extracted_fee_amount_ore',
        ] as $from => $to) {
            if (isset($data[$from]) && is_numeric($data[$from])) {
                $result[$to] = (int) $data[$from];
            }
        }

        return $result;
    }
}
