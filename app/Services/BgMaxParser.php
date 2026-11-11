<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Parses payment files and returns a uniform list of transactions.
 *
 * Supported formats:
 *   - BGMax v5 (Bankgirot standard, Swedish OCR payments)
 *     Record type TK01: incoming OCR/reference payments
 *     Field layout (0-indexed):
 *       [0-1]   Record type "01"
 *       [2-11]  Payee BGC number (10 chars)
 *       [12-36] OCR reference / free text (25 chars, left-justified)
 *       [37-48] Amount in öre (12 chars, right-justified, zero-padded)
 *       [49-56] Payment date YYYYMMDD (8 chars)
 *       [57-68] Sender BGC/bank account (12 chars)
 *
 *   - ISO 20022 camt.054 XML (new Swedish bank standard, all versions)
 *     Handles both structured OCR refs (CdtrRefInf/Ref) and unstructured
 *     free-text refs (Ustrd). Only CRDT entries are processed.
 *
 *   - Simple CSV / TXT (semicolon or comma separated)
 *     Required columns (case-insensitive, many Swedish bank synonyms accepted):
 *       Reference : referens, reference, meddelande, text, beskrivning, ocr, meddelandetext, betalningsreferens
 *       Amount    : belopp, amount, inbetalning, kredit, belopp(sek), summa, transaktionsbelopp
 *       Date      : datum, date, transaktionsdatum, bokföringsdatum, värdedatum, betaldatum
 *       Sender    : avsändare, sender, motpart, namn, betalare, betalarsnamn, avsändarnamn
 *     Amount: SEK with comma or dot decimal, thousands-separator spaces/NBSP,
 *             optional "kr" suffix, or raw öre integer ≥ 10000.
 *     Header-row detection: skips leading metadata rows until a row with ≥2 known column keywords.
 *     XLS/XLSX files must be exported to CSV first — binary Excel files are not supported.
 *
 * @return array<int, array{reference: string, amountOre: int, date: string, sender: string}>
 */
class BgMaxParser
{
    public function parse(string $content): array
    {
        // Reject binary XLS/XLSX before any encoding conversion — magic bytes are format-specific
        if (str_starts_with($content, "\xD0\xCF") || str_starts_with($content, 'PK')) {
            throw new \InvalidArgumentException(
                'Excel-filer stöds inte. Exportera filen som CSV eller TXT från din bank och försök igen.'
            );
        }

        $content = ltrim($content, "\xEF\xBB\xBF"); // strip UTF-8 BOM

        // Convert ISO-8859-1 / Windows-1252 to UTF-8 if needed (common in Swedish bank exports)
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        if ($this->isXml($content)) {
            return $this->parseXml($content);
        }

        if ($this->isBgMax($content)) {
            return $this->parseBgMax($content);
        }

        return $this->parseCsv($content);
    }

    private function isXml(string $content): bool
    {
        return str_starts_with(ltrim($content), '<');
    }

    private function isBgMax(string $content): bool
    {
        $first = substr(ltrim($content), 0, 2);
        return $first === '00';
    }

    /**
     * Parse ISO 20022 camt.054 XML (Bank-to-Customer Debit Credit Notification).
     * Supports all namespace versions (camt.054.001.xx).
     */
    private function parseXml(string $content): array
    {
        // Strip namespace declarations so SimpleXML works without prefix juggling
        $content = preg_replace('/\s+xmlns(?::\w+)?="[^"]*"/', '', $content) ?? $content;

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new \InvalidArgumentException('Ogiltig XML-fil: ' . implode(', ', $errors));
        }

        libxml_clear_errors();

        $rows = [];

        // camt.054: Document > BkToCstmrDbtCdtNtfctn > Ntfctn > Ntry
        foreach ($xml->xpath('//Ntry') as $entry) {
            // Skip debits — we only want credits to our account
            $indicator = (string) ($entry->CdtDbtInd ?? '');
            if ($indicator !== '' && $indicator !== 'CRDT') {
                continue;
            }

            $rawAmount = (string) ($entry->Amt ?? '');
            $amountOre = $this->parseAmount($rawAmount);

            if ($amountOre <= 0) {
                continue;
            }

            // Date: prefer booking date, fall back to value date
            $rawDate = (string) ($entry->BookgDt->Dt
                ?? $entry->BookgDt->DtTm
                ?? $entry->ValDt->Dt
                ?? $entry->ValDt->DtTm
                ?? '');
            $date = $this->parseDate(substr($rawDate, 0, 10)); // trim time portion

            $reference = '';
            $sender    = '';

            foreach ($entry->xpath('.//TxDtls') as $tx) {
                // Structured OCR reference
                $ref = (string) ($tx->RmtInf->Strd->CdtrRefInf->Ref ?? '');

                // Unstructured free-text reference
                if (empty($ref)) {
                    $ref = (string) ($tx->RmtInf->Ustrd ?? '');
                }

                // Debtor name (sender)
                $senderName = (string) ($tx->RltdPties->Dbtr->Nm ?? '');

                if (! empty($ref)) {
                    $reference = trim($ref);
                    $sender    = trim($senderName);
                    break; // take the first transaction detail with a reference
                }
            }

            if (empty($reference)) {
                continue;
            }

            $rows[] = compact('reference', 'amountOre', 'date', 'sender');
        }

        return $rows;
    }

    private function parseBgMax(string $content): array
    {
        $rows = [];

        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = rtrim($line);

            if (strlen($line) < 57) {
                continue;
            }

            if (substr($line, 0, 2) !== '01') {
                continue;
            }

            $reference = trim(substr($line, 12, 25));
            $amountOre = (int) ltrim(substr($line, 37, 12), '0') ?: 0;
            $dateStr   = substr($line, 49, 8);
            $sender    = trim(substr($line, 57));

            if (empty($reference) || $amountOre <= 0) {
                continue;
            }

            try {
                $date = Carbon::createFromFormat('Ymd', $dateStr)->toDateString();
            } catch (\Throwable) {
                $date = now()->toDateString();
            }

            $rows[] = compact('reference', 'amountOre', 'date', 'sender');
        }

        return $rows;
    }

    /** Column-name synonyms for Swedish bank export files (including Swedbank Swish reports). */
    private const REF_COLS    = ['referens', 'reference', 'meddelande', 'text', 'beskrivning', 'ocr', 'meddelandetext', 'betalningsreferens', 'orderreferens'];
    private const AMOUNT_COLS = ['belopp', 'amount', 'inbetalning', 'kredit', 'belopp(sek)', 'summa', 'transaktionsbelopp'];
    private const DATE_COLS   = ['datum', 'date', 'transaktionsdatum', 'bokföringsdatum', 'värdedatum', 'betaldatum', 'bokfdag', 'transdag', 'valutadag'];
    private const SENDER_COLS = ['avsändare', 'sender', 'motpart', 'namn', 'betalare', 'betalarsnamn', 'avsändarnamn'];

    private function parseCsv(string $content): array
    {
        $lines = preg_split('/\r?\n/', trim($content));

        if (\count($lines) < 2) {
            return [];
        }

        // Find the header row: first row that contains ≥2 known column keywords
        $headerIndex = null;
        $sep         = ';';

        foreach ($lines as $i => $line) {
            // Detect separator: tab first (Swedbank TXT), then semicolon, then comma
            if (str_contains($line, "\t")) {
                $testSep = "\t";
            } elseif (str_contains($line, ';')) {
                $testSep = ';';
            } else {
                $testSep = ',';
            }
            $cells    = array_map(fn ($h) => mb_strtolower(trim($h)), explode($testSep, $line));
            $allCols  = array_merge(self::REF_COLS, self::AMOUNT_COLS, self::DATE_COLS, self::SENDER_COLS);
            $hits     = \count(array_intersect($cells, $allCols));

            if ($hits >= 2) {
                $headerIndex = $i;
                $sep         = $testSep;
                break;
            }
        }

        if ($headerIndex === null) {
            throw new \InvalidArgumentException(
                'CSV-filen saknar kolumner "referens" och "belopp". ' .
                'Förväntad rubrikrad: datum;referens;belopp[;avsändare]'
            );
        }

        $headers = array_map(fn ($h) => mb_strtolower(trim($h)), explode($sep, $lines[$headerIndex]));

        $firstMatch = function (array $synonyms) use ($headers): int|false {
            foreach ($synonyms as $name) {
                $idx = array_search($name, $headers);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return false;
        };

        $refCol    = $firstMatch(self::REF_COLS);
        $amountCol = $firstMatch(self::AMOUNT_COLS);
        $dateCol   = $firstMatch(self::DATE_COLS);
        $senderCol = $firstMatch(self::SENDER_COLS);

        if ($refCol === false || $amountCol === false) {
            throw new \InvalidArgumentException(
                'CSV-filen saknar kolumner "referens" och "belopp". ' .
                'Förväntad rubrikrad: datum;referens;belopp[;avsändare]'
            );
        }

        $rows = [];

        foreach (\array_slice($lines, $headerIndex + 1) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $cells = explode($sep, $line);

            $reference = trim($cells[$refCol] ?? '');
            $rawAmount = trim($cells[$amountCol] ?? '');
            $rawDate   = $dateCol !== false ? trim($cells[$dateCol] ?? '') : '';
            $sender    = $senderCol !== false ? trim($cells[$senderCol] ?? '') : '';

            if (empty($reference) || empty($rawAmount)) {
                continue;
            }

            $amountOre = $this->parseAmount($rawAmount);

            if ($amountOre <= 0) {
                continue;
            }

            $date = $this->parseDate($rawDate);

            $rows[] = compact('reference', 'amountOre', 'date', 'sender');
        }

        return $rows;
    }

    /**
     * Parse amount to öre. Accepts:
     *   "250,00" or "250.00"         → 25000 öre
     *   "1 250,00" or "1 250.00"     → 125000 öre  (thousands separator)
     *   "1 250,00 kr" / "250,00 SEK" → strip currency suffix first
     *   "25000" (integer ≥ 10000)    → treated as öre already
     */
    private function parseAmount(string $raw): int
    {
        // Strip currency suffixes (kr, sek, etc.) and surrounding whitespace
        $clean = preg_replace('/\s*(kr|sek)\s*$/iu', '', trim($raw)) ?? $raw;

        // Detect decimal separator: if both comma and dot appear, the last one is decimal
        $hasComma = str_contains($clean, ',');
        $hasDot   = str_contains($clean, '.');

        if ($hasComma && $hasDot) {
            // e.g. "1.250,00" → dot is thousands, comma is decimal
            $lastComma = strrpos($clean, ',');
            $lastDot   = strrpos($clean, '.');
            if ($lastComma > $lastDot) {
                $clean = str_replace('.', '', $clean); // remove thousands dots
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean); // remove thousands commas
            }
        } elseif ($hasComma) {
            $clean = str_replace(',', '.', $clean);
        }

        // Remove remaining thousands separators (spaces, NBSP, apostrophes)
        $clean = str_replace([' ', "\u{00A0}", "'"], '', $clean);

        $float = (float) $clean;

        // If value looks like it's already in öre (no decimals, very large), keep it
        if (! str_contains($clean, '.') && $float >= 10000) {
            return (int) $float;
        }

        return (int) round($float * 100);
    }

    private function parseDate(string $raw): string
    {
        if (empty($raw)) {
            return now()->toDateString();
        }

        foreach (['Y-m-d', 'd/m/Y', 'Ymd', 'd.m.Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $raw)->toDateString();
            } catch (\Throwable) {
            }
        }

        return now()->toDateString();
    }
}
