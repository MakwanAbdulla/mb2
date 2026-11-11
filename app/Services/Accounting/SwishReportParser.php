<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

/**
 * Parses Swish transaction report exports in CSV or tab-separated TXT format.
 *
 * Both formats share the same column structure:
 *   Row 1: metadata line (starts with *)  e.g. "* Swish-rapport Avser 2026-01-01 - 2026-04-20 ..."
 *   Row 2: column headers
 *   Row 3+: transaction data
 *
 * CSV: comma-separated, period decimal separator, ISO-8859-1 encoded
 * TXT: tab-separated,   comma decimal separator, ISO-8859-1 encoded
 *
 * Columns (0-based):
 *   0  Radnr         row number
 *   1  Clnr          clearing number
 *   2  Kontonr       receiving account number
 *   3  Bokfdag       booking date      YYYY-MM-DD
 *   4  Transdag      transaction date  YYYY-MM-DD
 *   5  Valutadag     value date        YYYY-MM-DD
 *   6  Mottagarnr    merchant Swish number
 *   7  Mottagarnamn  merchant name
 *   8  Avsändarnr    sender phone
 *   9  Avsändarnamn  sender name
 *   10 Meddelande    message
 *   11 Tid           time HH:mm
 *   12 Belopp        amount
 *   13 Orderreferens order reference
 */
class SwishReportParser
{
    /** @var list<array{row_number: int|null, reason: string}> Rows skipped by the last parse() call. */
    private array $skippedRows = [];

    public static function fileHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Rows that could not be parsed and were left out of the last parse() result,
     * e.g. a trailing summary line or a row with a malformed date.
     *
     * @return list<array{row_number: int|null, reason: string}>
     */
    public function getSkippedRows(): array
    {
        return $this->skippedRows;
    }

    /**
     * Extract the account number (Kontonr, column 2) from the first data row.
     */
    public function parseAccountNumber(string $content): ?string
    {
        $lines  = $this->lines($this->normalise($content));
        $isTsv  = $this->detectTsv($lines);

        foreach ($lines as $i => $line) {
            if ($i < 2 || trim($line) === '') {
                continue;
            }
            $fields = $this->split($line, $isTsv);
            return isset($fields[2]) && $fields[2] !== '' ? trim($fields[2]) : null;
        }

        return null;
    }

    /**
     * Parse the period from the metadata line (row 1).
     * Returns [from, to] as date strings, or [null, null].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function parsePeriod(string $content): array
    {
        $lines = $this->lines($this->normalise($content));
        $meta  = $lines[0] ?? '';

        // "* Swish-rapport Avser 2026-01-01 - 2026-04-20 Skapad ..."
        // "* Swish-rapport Period 2024-10-18 - 2025-12-31 Skapad ..."
        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})/', $meta, $m)) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }

    /**
     * @return list<array{
     *   row_number: int,
     *   booking_date: string,
     *   transaction_date: string,
     *   value_date: string,
     *   swish_number: string,
     *   sender_phone: string,
     *   sender_name: string,
     *   message: string,
     *   transaction_time: string,
     *   amount_ore: int,
     *   order_reference: string,
     * }>
     */
    public function parse(string $content): array
    {
        $this->skippedRows = [];

        $content = $this->normalise($content);
        $lines   = $this->lines($content);
        $isTsv   = $this->detectTsv($lines);

        $rows = [];
        foreach ($lines as $i => $line) {
            if ($i < 2 || trim($line) === '') {
                continue;
            }

            $f = $this->split($line, $isTsv);
            if (count($f) < 13) {
                $this->skippedRows[] = ['row_number' => is_numeric($f[0] ?? null) ? (int) $f[0] : null, 'reason' => 'För få kolumner'];
                continue;
            }

            $rowNumber       = (int) trim($f[0]);
            $bookingDate     = $this->parseDate($f[3]);
            $transactionDate = $this->parseDate($f[4]);
            $valueDate       = $this->parseDate($f[5]);

            // Booking/transaction/value date are required, non-nullable columns —
            // skip rows we can't confidently date rather than write a bad value
            // (e.g. a trailing summary line that still happens to have 13+ columns).
            if ($bookingDate === '' || $transactionDate === '' || $valueDate === '') {
                $this->skippedRows[] = ['row_number' => $rowNumber, 'reason' => 'Ogiltigt eller saknat datum'];
                continue;
            }

            $rows[] = [
                'row_number'       => $rowNumber,
                'booking_date'     => $bookingDate,
                'transaction_date' => $transactionDate,
                'value_date'       => $valueDate,
                'swish_number'     => trim($f[6]),
                'sender_phone'     => trim($f[8]),
                'sender_name'      => trim($f[9]),
                'message'          => trim($f[10]),
                'transaction_time' => trim($f[11]),
                'amount_ore'       => $this->parseAmount($f[12], $isTsv),
                'order_reference'  => trim($f[13] ?? ''),
            ];
        }

        return $rows;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function normalise(string $content): string
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        return $content;
    }

    private function lines(string $content): array
    {
        return preg_split('/\r?\n/', rtrim($content));
    }

    private function detectTsv(array $lines): bool
    {
        return str_contains($lines[1] ?? '', "\t");
    }

    private function split(string $line, bool $isTsv): array
    {
        return $isTsv
            ? str_getcsv($line, "\t")
            : str_getcsv($line, ',', '"');
    }

    private function parseDate(string $raw): string
    {
        $raw = trim($raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
    }

    private function parseAmount(string $raw, bool $isTsv): int
    {
        $raw = trim($raw);

        if ($isTsv) {
            $raw = str_replace([' ', "\xc2\xa0", "\xa0"], '', $raw);
            $raw = str_replace(',', '.', $raw);
        }

        return (int) round((float) $raw * 100);
    }
}
