<?php

use App\Services\Accounting\BankStatementParser;

// ── CSV format ────────────────────────────────────────────────────────────────

function csvStatement(array $rows = []): string
{
    $meta   = '* Kontoutdrag Kontonummer:12345678 Från:2027-01-01 Till:2027-12-31';
    $header = 'Radnr,Clnr,Kontonr,Produkt,Valuta,Bokfdag,Transdag,Valutadag,Referens,Text,Belopp,Saldo';

    $lines = [$meta, $header];
    foreach ($rows as $r) {
        $lines[] = implode(',', $r);
    }

    return implode("\n", $lines);
}

function csvRow(array $overrides = []): array
{
    return array_values(array_replace([
        'radnr'    => '1',
        'clnr'     => '8000',
        'kontonr'  => '12345678',
        'produkt'  => 'Privatkonto',
        'valuta'   => 'SEK',
        'bokfdag'  => '2027-06-15',
        'transdag' => '2027-06-14',
        'valdag'   => '2027-06-15',
        'ref'      => 'REF001',
        'text'     => 'Swish +46701234567',
        'belopp'   => '150.00',
        'saldo'    => '5000.00',
    ], $overrides));
}

// ── parse() — CSV ─────────────────────────────────────────────────────────────

test('parse CSV: extracts booking and transaction dates', function () {
    $content = csvStatement([csvRow()]);
    $rows    = (new BankStatementParser)->parse($content);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['booking_date'])->toBe('2027-06-15')
        ->and($rows[0]['transaction_date'])->toBe('2027-06-14');
});

test('parse CSV: converts amount to öre', function () {
    $content = csvStatement([csvRow(['belopp' => '1250.50'])]);
    $rows    = (new BankStatementParser)->parse($content);

    expect($rows[0]['amount_ore'])->toBe(125050);
});

test('parse CSV: negative amounts are negative öre', function () {
    $content = csvStatement([csvRow(['belopp' => '-200.00'])]);
    $rows    = (new BankStatementParser)->parse($content);

    expect($rows[0]['amount_ore'])->toBe(-20000);
});

test('parse CSV: extracts reference and description', function () {
    $content = csvStatement([csvRow(['ref' => 'OCR-123', 'text' => 'Spotify AB'])]);
    $rows    = (new BankStatementParser)->parse($content);

    expect($rows[0]['reference'])->toBe('OCR-123')
        ->and($rows[0]['description'])->toBe('Spotify AB');
});

test('parse CSV: skips rows with fewer than 12 columns', function () {
    $meta    = '* Meta';
    $header  = 'A,B,C';
    $content = "{$meta}\n{$header}\n1,2,3"; // only 3 columns

    $rows = (new BankStatementParser)->parse($content);

    expect($rows)->toBeEmpty();
});

test('parse CSV: empty content returns empty array', function () {
    expect((new BankStatementParser)->parse(''))->toBeEmpty();
});

// ── parse() — TSV ─────────────────────────────────────────────────────────────

function tsvStatement(array $rows = []): string
{
    $meta   = "* Kontoutdrag\tKontonummer:12345678";
    $header = "Radnr\tClnr\tKontonr\tProdukt\tValuta\tBokfdag\tTransdag\tValutadag\tReferens\tText\tBelopp\tSaldo";

    $lines = [$meta, $header];
    foreach ($rows as $r) {
        $lines[] = implode("\t", $r);
    }

    return implode("\n", $lines);
}

test('parse TSV: detects tab-separated format and converts amount', function () {
    $row     = ['1', '8000', '12345678', 'Privatkonto', 'SEK', '2027-06-15', '2027-06-14', '2027-06-15', 'REF002', 'Handkassa', '1 500,00', '10 000,00'];
    $content = tsvStatement([$row]);
    $rows    = (new BankStatementParser)->parse($content);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['amount_ore'])->toBe(150000)
        ->and($rows[0]['booking_date'])->toBe('2027-06-15');
});

test('parse TSV: handles non-breaking spaces as thousand separators', function () {
    $row = ['1', '8000', '12345678', 'Privatkonto', 'SEK', '2027-06-15', '2027-06-14', '2027-06-15', '', 'Test', "2\xc2\xa0500,00", "20\xc2\xa0000,00"];
    $content = tsvStatement([$row]);
    $rows    = (new BankStatementParser)->parse($content);

    expect($rows[0]['amount_ore'])->toBe(250000);
});

// ── parseAccountNumber() ──────────────────────────────────────────────────────

test('parseAccountNumber extracts account from first data row CSV', function () {
    $content = csvStatement([csvRow(['kontonr' => '87654321'])]);
    $result  = (new BankStatementParser)->parseAccountNumber($content);

    expect($result)->toBe('87654321');
});

test('parseAccountNumber returns null for malformed content', function () {
    expect((new BankStatementParser)->parseAccountNumber("only one line"))->toBeNull();
});

// ── fileHash() ────────────────────────────────────────────────────────────────

test('fileHash returns consistent SHA-256 for same content', function () {
    $content = 'test content';
    expect(BankStatementParser::fileHash($content))->toBe(BankStatementParser::fileHash($content));
});

test('fileHash returns different hashes for different content', function () {
    expect(BankStatementParser::fileHash('a'))->not->toBe(BankStatementParser::fileHash('b'));
});

// ── importHash() ─────────────────────────────────────────────────────────────

test('importHash produces stable hash for same inputs', function () {
    $row = [
        'booking_date' => '2027-06-15',
        'amount_ore'   => 150_00,
        'balance_ore'  => 5000_00,
        'reference'    => 'REF001',
        'description'  => 'Test',
    ];

    $h1 = BankStatementParser::importHash(1, $row);
    $h2 = BankStatementParser::importHash(1, $row);

    expect($h1)->toBe($h2);
});

test('importHash differs for different bank accounts', function () {
    $row = [
        'booking_date' => '2027-06-15',
        'amount_ore'   => 100_00,
        'balance_ore'  => 1000_00,
        'reference'    => 'REF',
        'description'  => 'Test',
    ];

    expect(BankStatementParser::importHash(1, $row))->not->toBe(BankStatementParser::importHash(2, $row));
});

test('importHash differs when balance differs (duplicate amounts same day)', function () {
    $base = [
        'booking_date' => '2027-06-15',
        'amount_ore'   => 50_00,
        'reference'    => 'REF',
        'description'  => 'Swish',
    ];

    $r1 = array_merge($base, ['balance_ore' => 1000_00]);
    $r2 = array_merge($base, ['balance_ore' => 1050_00]);

    expect(BankStatementParser::importHash(1, $r1))->not->toBe(BankStatementParser::importHash(1, $r2));
});
