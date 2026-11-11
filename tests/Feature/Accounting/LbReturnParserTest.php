<?php

use App\Services\Accounting\LbReturnParser;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Builds a minimal valid LB return file.
 *
 * Record 11 (header):   pos 3-12 BG, 13-18 file date YYMMDD, 19-44 type label, 45-50 payment date
 * Record 40 (opening):  pos 3-end: 4 × (13 digits + sign char)
 * Record 41 (payment):  same structure
 * Record 42 (closing):  same structure
 * Record 29 (trailer):  pos 13-20 record count
 */
function lbHeader(
    string $bg   = '1234567890',
    string $date = '270615',
    string $type = 'AUTOGIRO              ',
    string $payDate = '270615',
): string {
    $bgPad  = str_pad($bg, 10, '0', STR_PAD_LEFT);
    $typePad = str_pad($type, 26);
    return "11{$bgPad}{$date}{$typePad}{$payDate}";
}

function lbAmount(int $ore, bool $negative = false): string
{
    $abs    = abs($ore);
    $digits = str_pad((string) $abs, 13, '0', STR_PAD_LEFT);
    $sign   = $negative ? '-' : ' ';
    return $digits . $sign;
}

function lbRecord40(int $openInv, int $openCred, int $readInv, int $readCred): string
{
    return '40' . lbAmount($openInv) . lbAmount($openCred) . lbAmount($readInv) . lbAmount($readCred);
}

function lbRecord41(int $paidInv, int $settledCred, int $cancelledInv, int $cancelledCred): string
{
    return '41' . lbAmount($paidInv) . lbAmount($settledCred) . lbAmount($cancelledInv) . lbAmount($cancelledCred);
}

function lbRecord42(int $closingInv, int $closingCred, int $rejectedInv, int $rejectedCred): string
{
    return '42' . lbAmount($closingInv) . lbAmount($closingCred) . lbAmount($rejectedInv) . lbAmount($rejectedCred);
}

function lbTrailer(int $count): string
{
    // Parser reads substr($line, 12, 8) — 2 type + 10 filler = pos 12 for count
    return '29' . str_repeat(' ', 10) . str_pad((string) $count, 8, '0', STR_PAD_LEFT);
}

function lbFile(
    int $openInv = 0, int $openCred = 0,
    int $readInv = 100_00, int $readCred = 0,
    int $paidInv = 100_00, int $settledCred = 0, int $cancelledInv = 0, int $cancelledCred = 0,
    int $closingInv = 0, int $closingCred = 0, int $rejectedInv = 0, int $rejectedCred = 0,
    int $recordCount = 4,
): string {
    return implode("\n", [
        lbHeader(),
        lbRecord40($openInv, $openCred, $readInv, $readCred),
        lbRecord41($paidInv, $settledCred, $cancelledInv, $cancelledCred),
        lbRecord42($closingInv, $closingCred, $rejectedInv, $rejectedCred),
        lbTrailer($recordCount),
    ]);
}

// ── Header parsing ────────────────────────────────────────────────────────────

test('parse: extracts BG number from header', function () {
    $result = (new LbReturnParser)->parse(lbFile());

    expect($result['bg_number'])->toBe('1234567890');
});

test('parse: strips leading zeros from BG number', function () {
    $content = str_replace('1234567890', '0001234567', lbFile());
    $result  = (new LbReturnParser)->parse($content);

    expect($result['bg_number'])->toBe('1234567');
});

test('parse: converts YYMMDD file date to YYYY-MM-DD', function () {
    $result = (new LbReturnParser)->parse(lbFile());

    expect($result['file_date'])->toBe('2027-06-15');
});

test('parse: year >= 50 is interpreted as 19xx', function () {
    // File date with YY=65 → 1965
    $header  = lbHeader(date: '650101');
    $content = $header . "\n" . lbRecord40(0, 0, 0, 0) . "\n" . lbRecord41(0, 0, 0, 0) . "\n" . lbRecord42(0, 0, 0, 0);
    $result  = (new LbReturnParser)->parse($content);

    expect($result['file_date'])->toBe('1965-01-01');
});

test('parse: year < 50 is interpreted as 20xx', function () {
    $result = (new LbReturnParser)->parse(lbFile());

    expect($result['file_date'])->toBe('2027-06-15');
});

// ── Summary records ───────────────────────────────────────────────────────────

test('parse: extracts read_invoices from record 40', function () {
    $result = (new LbReturnParser)->parse(lbFile(readInv: 500_00));

    expect($result['read_invoices'])->toBe(500_00);
});

test('parse: extracts paid_invoices from record 41', function () {
    $result = (new LbReturnParser)->parse(lbFile(paidInv: 300_00));

    expect($result['paid_invoices'])->toBe(300_00);
});

test('parse: extracts rejected_invoices from record 42', function () {
    $result = (new LbReturnParser)->parse(lbFile(rejectedInv: 50_00));

    expect($result['rejected_invoices'])->toBe(50_00);
});

test('parse: extracts record count from trailer', function () {
    $result = (new LbReturnParser)->parse(lbFile(recordCount: 7));

    expect($result['record_count'])->toBe(7);
});

// ── Transaction (record 14) ───────────────────────────────────────────────────

test('parse: extracts individual rejected transaction from record 14', function () {
    $bg     = str_pad('9876543210', 10, '0', STR_PAD_LEFT);
    $ref    = str_pad('OCR001', 12);
    $amt    = str_pad('5000', 13, '0', STR_PAD_LEFT) . ' '; // 5000 öre, positive
    $date   = '270701';
    $status = '00';
    $text   = str_pad('Avvisad', 34);

    $record14 = "14{$bg}{$ref}{$amt}{$date}{$status}{$text}";

    $content = implode("\n", [
        lbHeader(),
        lbRecord40(0, 0, 100_00, 0),
        $record14,
        lbRecord41(0, 0, 0, 0),
        lbRecord42(0, 0, 100_00, 0),
        lbTrailer(5),
    ]);

    $result = (new LbReturnParser)->parse($content);

    expect($result['transactions'])->toHaveCount(1)
        ->and($result['transactions'][0]['amount'])->toBe(5000)
        ->and($result['transactions'][0]['status'])->toBe('00');
});

// ── Edge cases ────────────────────────────────────────────────────────────────

test('parse: empty content returns zero-filled result', function () {
    $result = (new LbReturnParser)->parse('');

    expect($result['bg_number'])->toBe('')
        ->and($result['paid_invoices'])->toBe(0)
        ->and($result['transactions'])->toBeEmpty();
});

test('parse: handles CRLF line endings', function () {
    $content = str_replace("\n", "\r\n", lbFile(paidInv: 200_00));
    $result  = (new LbReturnParser)->parse($content);

    expect($result['paid_invoices'])->toBe(200_00);
});
