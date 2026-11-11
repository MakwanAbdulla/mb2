<?php

namespace App\Services\Accounting;

/**
 * Parses the monthly "edocument bankavgifter" PDF from Sparbanken Sjuhärad.
 *
 * The PDF is converted with `pdftotext -layout` before parsing. The structure:
 *   - Period header: "Pris för perioden  YYYY-MM-DD - YYYY-MM-DD"
 *   - Fee block per service: description line, then agreement-number + amount line
 *   - Total footer: "Summa:  1 234,56"
 *
 * Agreement numbers come in two forms:
 *   - 10-digit (Swish Handel): 1234140489
 *   - 13-digit with "003" suffix (Swish Företag): 1236700413003 → base 1236700413
 */
class SparbankFeeParser
{
    /**
     * @return array{
     *   period_start: string,
     *   period_end: string,
     *   total_ore: int,
     *   lines: list<array{description: string, agreement_number: string, count: int|null, unit_price_ore: int|null, amount_ore: int}>
     * }|null  null if the file could not be parsed
     */
    public function parse(string $pdfPath): ?array
    {
        $text = $this->extractText($pdfPath);

        if (! $text) {
            return null;
        }

        $period = $this->parsePeriod($text);

        if (! $period) {
            return null;
        }

        $lines = $this->parseFeeLines($text);
        $total = $this->parseTotal($text);

        return [
            'period_start' => $period[0],
            'period_end'   => $period[1],
            'total_ore'    => $total ?? array_sum(array_column($lines, 'amount_ore')),
            'lines'        => $lines,
        ];
    }

    // -------------------------------------------------------------------------

    private function extractText(string $pdfPath): ?string
    {
        $escaped = escapeshellarg($pdfPath);
        $output  = shell_exec("pdftotext -layout {$escaped} -");

        return $output ?: null;
    }

    /** @return array{string, string}|null [YYYY-MM-DD, YYYY-MM-DD] */
    private function parsePeriod(string $text): ?array
    {
        if (! preg_match('/Pris\s+f[oö]r\s+perioden\s+(\d{4}-\d{2}-\d{2})\s+-\s+(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /** Parse "Summa: 1 234,56" → amount in öre */
    private function parseTotal(string $text): ?int
    {
        if (! preg_match('/Summa:.*?(\d[\d ]*,\d{2})\s*$/m', $text, $m)) {
            return null;
        }

        return $this->parseAmount($m[1]);
    }

    /**
     * Extract individual fee lines.
     *
     * pdftotext -layout produces two distinct patterns (see actual PDF output):
     *
     * Type A — fixed fee (amount on description line, agreement alone on next line):
     *   "Swish Handel-ta emot     123 414 04 89     50,00"
     *   "1234140489"
     *
     * Type B — per-transaction fee (agreement + display + amount on data line):
     *   "Swish Handel-ta emot Swish - anko    antal 67 à pris 3,00"
     *   "1234140489     123 414 04 89     201,00"
     *
     * @return list<array{description: string, agreement_number: string, count: int|null, unit_price_ore: int|null, amount_ore: int}>
     */
    private function parseFeeLines(string $text): array
    {
        $lines  = array_values(array_filter(
            array_map('rtrim', explode("\n", $text)),
            fn ($l) => trim($l) !== ''
        ));

        $result = [];
        $count  = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $trimmed = trim($lines[$i]);

            // A line that starts with a 10-13 digit agreement number is always a data line.
            if (! preg_match('/^(\d{10,13})\b/', $trimmed, $numMatch)) {
                continue;
            }

            $agreementRaw = $numMatch[1];
            $agreement    = $this->normaliseAgreement($agreementRaw);

            // Does this data line carry an amount? (trailing "NNN,NN")
            $amountOnDataLine = null;
            if (preg_match('/(\d+,\d{2})\s*$/', $trimmed, $amtMatch)) {
                $candidate = $this->parseAmount($amtMatch[1]);
                // Sanity: must be a plausible fee (> 0 and not just a display phone number)
                if ($candidate > 0) {
                    $amountOnDataLine = $candidate;
                }
            }

            $prevLine = $i > 0 ? $lines[$i - 1] : '';
            $prevTrimmed = trim($prevLine);

            if ($amountOnDataLine !== null) {
                // Type B: amount on data line, description+count+unit_price on prev line.
                [$desc, $lineCount, $unitPrice] = $this->parseDescriptionLine($prevTrimmed);

                $result[] = [
                    'description'     => $desc,
                    'agreement_number' => $agreement,
                    'count'           => $lineCount,
                    'unit_price_ore'  => $unitPrice,
                    'amount_ore'      => $amountOnDataLine,
                ];
            } else {
                // Type A: this line is just the agreement number.
                // The amount + description are on the PREVIOUS line.
                if (! preg_match('/(\d+,\d{2})\s*$/', $prevLine, $amtMatch)) {
                    continue; // Cannot determine amount — skip.
                }

                $amount = $this->parseAmount($amtMatch[1]);
                if ($amount <= 0) {
                    continue;
                }

                // Extract description from start of prev line (before the large-whitespace region).
                $desc = preg_replace('/\s{3,}.*$/', '', $prevTrimmed);
                $desc = trim($desc);

                $result[] = [
                    'description'     => $desc,
                    'agreement_number' => $agreement,
                    'count'           => null,
                    'unit_price_ore'  => null,
                    'amount_ore'      => $amount,
                ];
            }
        }

        return $result;
    }

    /**
     * Parse a description line that may contain "antal N à pris M,MM".
     * Returns [description, count|null, unit_price_ore|null].
     *
     * @return array{string, int|null, int|null}
     */
    private function parseDescriptionLine(string $line): array
    {
        $count     = null;
        $unitPrice = null;

        if (preg_match('/antal\s+(\d+)\s+[àa]\s+pris\s+([\d,]+)/iu', $line, $m)) {
            $count     = (int) $m[1];
            $unitPrice = $this->parseAmount($m[2]);
        }

        // Description = text before "antal" or before large whitespace
        $desc = preg_replace('/\s+antal\s+.*/i', '', $line);
        $desc = preg_replace('/\s{3,}.*$/', '', $desc);
        $desc = trim($desc);

        return [$desc, $count, $unitPrice];
    }

    /**
     * Normalise a Swish agreement number.
     * Swish Företag numbers end with "003" — strip that suffix to get the base number.
     */
    private function normaliseAgreement(string $raw): string
    {
        if (strlen($raw) === 13 && str_ends_with($raw, '003')) {
            return substr($raw, 0, 10);
        }

        return $raw;
    }

    /**
     * Parse a Swedish decimal amount string (e.g. "1 234,56") to öre.
     * Spaces are thousand separators, comma is decimal separator.
     */
    private function parseAmount(string $raw): int
    {
        $normalised = str_replace([' ', "\u{00A0}"], '', $raw); // remove spaces/NBSP
        $normalised = str_replace(',', '.', $normalised);

        return (int) round((float) $normalised * 100);
    }
}
