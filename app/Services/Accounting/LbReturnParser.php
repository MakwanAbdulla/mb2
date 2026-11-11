<?php

namespace App\Services\Accounting;

/**
 * Parses Bankgirot LB Återredovisning files (vendor payment confirmation).
 *
 * Record types:
 *   11 - File header (BG number, file date, payment date)
 *   14 - Individual rejected/adjusted payment (optional, only on errors)
 *   29 - File trailer
 *   40 - Opening balance + amounts read (ingående saldo + inläst)
 *   41 - Payment results (betalda / avräknade / makulerade / avvisade)
 *   42 - Closing balance (utgående saldo)
 *
 * Amounts are 13-digit integers in öre (last 2 digits = öre),
 * followed by a sign character: ' ' = positive, '-' = negative.
 *
 * Returns a structured summary array plus any individual rejected lines.
 */
class LbReturnParser
{
    public function parse(string $content): array
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        $lines = preg_split('/\r?\n/', rtrim($content));

        $result = [
            'bg_number'       => '',
            'file_date'       => '',
            'payment_date'    => '',
            'type_label'      => '',

            // Record 40 — opening + read
            'opening_invoices'        => 0,  // ingående saldo fakturor
            'opening_credits'         => 0,  // ingående saldo kreditfakturor
            'read_invoices'           => 0,  // inläst fakturor
            'read_credits'            => 0,  // inläst kreditfakturor

            // Record 41 — payment results
            'paid_invoices'           => 0,  // betalda fakturor
            'settled_credits'         => 0,  // avräknade kreditfakturor
            'cancelled_invoices'      => 0,  // makulerade fakturor
            'cancelled_credits'       => 0,  // makulerade kreditfakturor

            // Record 42 — closing + rejected
            'closing_invoices'        => 0,  // utgående saldo fakturor
            'closing_credits'         => 0,  // utgående saldo kreditfakturor
            'rejected_invoices'       => 0,  // avvisade fakturor
            'rejected_credits'        => 0,  // avvisade kreditfakturor

            // Individual rejected/adjusted payment lines (record 14)
            'transactions'            => [],

            'record_count'            => 0,
            'parse_errors'            => [],
        ];

        foreach ($lines as $line) {
            if (trim($line) === '') continue;

            $type = substr($line, 0, 2);

            match ($type) {
                '11' => $this->parseHeader($line, $result),
                '40' => $this->parseSummaryRecord($line, $result, 'opening'),
                '41' => $this->parseSummaryRecord($line, $result, 'payment'),
                '42' => $this->parseSummaryRecord($line, $result, 'closing'),
                '14' => $this->parseTransaction($line, $result),
                '29' => $this->parseTrailer($line, $result),
                default => null,
            };
        }

        return $result;
    }

    private function parseHeader(string $line, array &$result): void
    {
        // Pos 3-12: BG number (10 chars, zero-padded)
        // Pos 13-18: File date YYMMDD
        // Pos 19-44: Type label (26 chars)
        // Pos 45-50: Payment date YYMMDD
        $bgRaw          = trim(substr($line, 2, 10));
        $result['bg_number']    = ltrim($bgRaw, '0');
        $result['file_date']    = $this->parseDate(substr($line, 12, 6));
        $result['type_label']   = trim(substr($line, 18, 26));
        $result['payment_date'] = $this->parseDate(substr($line, 44, 6));
    }

    /**
     * Records 40, 41, 42 each contain 4 signed amounts of 14 chars (13 digits + sign).
     *
     * Record 40: opening_invoices, opening_credits, read_invoices,   read_credits
     * Record 41: paid_invoices,    settled_credits, cancelled_invoices, cancelled_credits
     * Record 42: closing_invoices, closing_credits, rejected_invoices, rejected_credits
     */
    private function parseSummaryRecord(string $line, array &$result, string $section): void
    {
        $fieldMap = [
            'opening' => ['opening_invoices', 'opening_credits', 'read_invoices',      'read_credits'],
            'payment' => ['paid_invoices',    'settled_credits', 'cancelled_invoices',  'cancelled_credits'],
            'closing' => ['closing_invoices', 'closing_credits', 'rejected_invoices',   'rejected_credits'],
        ];

        $fields = $fieldMap[$section];
        $pos    = 2; // skip record type

        foreach ($fields as $i => $key) {
            // 13 digits + 1 sign char = 14 chars per amount
            // But there seems to be a space separator every other field in practice.
            // We'll try reading 13+sign, then skip any extra space.
            $digits = substr($line, $pos, 13);
            $sign   = substr($line, $pos + 13, 1);

            $amount = (int) ltrim($digits, '0') ?: 0;
            if ($sign === '-') {
                $amount = -$amount;
            }

            $result[$key] = $amount;
            $pos += 14;

            // Some implementations add a space separator between pairs
            if ($i === 1 && isset($line[$pos]) && $line[$pos] === ' ') {
                // skip it — but don't advance if already aligned
            }
        }
    }

    /**
     * Record 14 — individual rejected or adjusted payment.
     * Format varies by bank, but typically:
     *   Pos 3-12:  BG number of payee
     *   Pos 13-24: Reference/OCR (12 chars)
     *   Pos 25-37: Amount (13 digits, öre)
     *   Pos 38:    Sign
     *   Pos 39-44: Due date YYMMDD
     *   Pos 45-46: Status code
     *   Pos 47-80: Free text / payee name
     */
    private function parseTransaction(string $line, array &$result): void
    {
        $result['transactions'][] = [
            'bg_payee'   => ltrim(trim(substr($line, 2, 10)), '0'),
            'reference'  => trim(substr($line, 12, 12)),
            'amount'     => $this->parseAmount(substr($line, 24, 14)),
            'due_date'   => $this->parseDate(substr($line, 38, 6)),
            'status'     => trim(substr($line, 44, 2)),
            'text'       => trim(substr($line, 46)),
        ];
    }

    private function parseTrailer(string $line, array &$result): void
    {
        // Pos 13-20: number of records
        $countStr = substr($line, 12, 8);
        $result['record_count'] = (int) ltrim($countStr, '0');
    }

    /** Parse YYMMDD into YYYY-MM-DD. */
    private function parseDate(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) !== 6 || ! ctype_digit($raw)) {
            return '';
        }
        $yy = (int) substr($raw, 0, 2);
        $mm = substr($raw, 2, 2);
        $dd = substr($raw, 4, 2);
        $yyyy = $yy >= 50 ? "19{$yy}" : "20{$yy}";
        return "{$yyyy}-{$mm}-{$dd}";
    }

    /** Parse 13-digit amount string + sign char into öre integer. */
    private function parseAmount(string $raw): int
    {
        $digits = substr($raw, 0, 13);
        $sign   = substr($raw, 13, 1);
        $amount = (int) ltrim($digits, '0') ?: 0;
        return $sign === '-' ? -$amount : $amount;
    }
}
