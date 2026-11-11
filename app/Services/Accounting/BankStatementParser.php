<?php

namespace App\Services\Accounting;


/**
 * Parses Swedbank bank statement exports in CSV or tab-separated TXT format.
 *
 * Both formats share the same structure:
 *   Row 1: metadata line (starts with *)
 *   Row 2: column headers
 *   Row 3+: transaction data
 *
 * CSV: comma-separated, period decimal separator, ISO-8859-1 encoded
 * TXT: tab-separated,   comma decimal separator, ISO-8859-1 encoded
 *
 * Returns an array of parsed row arrays, each with keys:
 *   booking_date, transaction_date, value_date, reference,
 *   description, amount_ore, balance_ore
 */
class BankStatementParser
{
    /**
     * Extract the account number (Kontonr, column 2) from the first data row.
     * Returns null if the file is malformed or empty.
     */
    public function parseAccountNumber(string $content): ?string
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        $lines = preg_split('/\r?\n/', rtrim($content));

        if (\count($lines) < 3) {
            return null;
        }

        $headerLine = $lines[1] ?? '';
        $isTsv      = str_contains($headerLine, "\t");

        // First data line is index 2
        $fields = $isTsv
            ? str_getcsv($lines[2], "\t")
            : str_getcsv($lines[2], ',', '"');

        return isset($fields[2]) && $fields[2] !== '' ? trim($fields[2]) : null;
    }

    /**
     * Compute a SHA-256 fingerprint of the raw file content.
     * Used to detect re-uploads of the same file.
     */
    public static function fileHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /** @return list<array{booking_date: string, transaction_date: string, value_date: string, reference: string, description: string, amount_ore: int, balance_ore: int}> */
    public function parse(string $content): array
    {
        // Normalise encoding — files are ISO-8859-1
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        // Detect format by sniffing: if the header row contains tabs → TXT, else → CSV
        $lines = preg_split('/\r?\n/', rtrim($content));

        if (\count($lines) < 2) {
            return [];
        }

        $headerLine = $lines[1] ?? '';
        $isTsv      = str_contains($headerLine, "\t");

        $rows = [];
        foreach ($lines as $i => $line) {
            if ($i < 2 || trim($line) === '') {
                continue; // skip metadata + header
            }

            $fields = $isTsv
                ? str_getcsv($line, "\t")
                : str_getcsv($line, ',', '"');

            if (\count($fields) < 12) {
                continue;
            }

            // Column order: Radnr, Clnr, Kontonr, Produkt, Valuta,
            //               Bokfdag, Transdag, Valutadag, Referens, Text, Belopp, Saldo
            $rawAmount  = $this->parseAmount($fields[10], $isTsv);
            $rawBalance = $this->parseAmount($fields[11], $isTsv);

            $rows[] = [
                'booking_date'     => $this->parseDate($fields[5]),
                'transaction_date' => $this->parseDate($fields[6]),
                'value_date'       => $this->parseDate($fields[7]),
                'reference'        => trim($fields[8]),
                'description'      => trim($fields[9]),
                'amount_ore'       => $rawAmount,
                'balance_ore'      => $rawBalance,
            ];
        }

        return $rows;
    }

    /**
     * Parse a date string (YYYY-MM-DD) into a Carbon date string.
     * Returns an empty string if the date is invalid.
     */
    private function parseDate(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return '';
    }

    /**
     * Parse an amount string into öre (integer).
     *
     * CSV uses period decimal (1234.50 or -1234.50).
     * TXT uses comma decimal (1 234,50 or -1 234,50, may have spaces as thousands).
     */
    private function parseAmount(string $raw, bool $isTsv): int
    {
        $raw = trim($raw);

        if ($isTsv) {
            // Remove spaces (thousands separator), replace comma with period
            $raw = str_replace([' ', "\xc2\xa0", "\xa0"], '', $raw); // remove regular + non-breaking spaces
            $raw = str_replace(',', '.', $raw);
        }

        // Now $raw should be like "1234.50" or "-1234.50"
        return (int) round((float) $raw * 100);
    }

    /**
     * Build a deduplication hash for a parsed row + bank_account_id.
     *
     * balance_ore is the running account balance after this transaction.
     * It is unique per row even when description/amount/date are identical
     * (e.g. three 50 kr Swish payments from the same sender in one day).
     */
    public static function importHash(int $bankAccountId, array $row): string
    {
        return hash('sha256', implode('|', [
            $bankAccountId,
            $row['booking_date'],
            $row['amount_ore'],
            $row['balance_ore'],
            $row['reference'],
            $row['description'],
        ]));
    }
}
