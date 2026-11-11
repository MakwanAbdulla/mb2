<?php

use App\Services\Accounting\SwishReportParser;

// ── Helpers ───────────────────────────────────────────────────────────────────

function swishCsvHeader(string $period = '2027-01-01 - 2027-06-30'): string
{
    return "* Swish-rapport Avser {$period} Skapad 2027-07-01\n"
        . 'Radnr,Clnr,Kontonr,Bokfdag,Transdag,Valutadag,Mottagarnr,Mottagarnamn,Avsändarnr,Avsändarnamn,Meddelande,Tid,Belopp,Orderreferens';
}

function swishCsvRow(array $overrides = []): string
{
    $defaults = [
        'radnr'     => '1',
        'clnr'      => '8000',
        'kontonr'   => '12345678',
        'bokfdag'   => '2027-06-15',
        'transdag'  => '2027-06-14',
        'valdag'    => '2027-06-15',
        'mottnr'    => '1231234567',
        'mottnamn'  => 'Armagedon',
        'avsndnr'   => '+46701234567',
        'avsndnamn' => 'Kalle Johansson',
        'msg'       => 'Avgift juni',
        'tid'       => '14:30',
        'belopp'    => '150.00',
        'orderref'  => 'pi_test123',
    ];

    return implode(',', array_values(array_replace($defaults, $overrides)));
}

function swishCsvContent(array $rows = []): string
{
    if (empty($rows)) {
        $rows = [swishCsvRow()];
    }
    return swishCsvHeader() . "\n" . implode("\n", $rows);
}

function swishTsvContent(array $overrides = []): string
{
    $meta   = "* Swish-rapport Period 2027-01-01 - 2027-06-30 Skapad 2027-07-01";
    $header = "Radnr\tClnr\tKontonr\tBokfdag\tTransdag\tValutadag\tMottagarnr\tMottagarnamn\tAvsändarnr\tAvsändarnamn\tMeddelande\tTid\tBelopp\tOrderreferens";
    $defaults = [
        '1', '8000', '12345678',
        '2027-06-15', '2027-06-14', '2027-06-15',
        '1231234567', 'Armagedon',
        '+46701234567', 'Kalle Johansson',
        'Avgift', '14:30',
        '1 500,00',
        'pi_tsv',
    ];

    $row = array_values(array_replace($defaults, $overrides));
    return "{$meta}\n{$header}\n" . implode("\t", $row);
}

// ── parse() — CSV ─────────────────────────────────────────────────────────────

test('parse CSV: extracts dates and amount', function () {
    $rows = (new SwishReportParser)->parse(swishCsvContent());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['booking_date'])->toBe('2027-06-15')
        ->and($rows[0]['transaction_date'])->toBe('2027-06-14')
        ->and($rows[0]['amount_ore'])->toBe(15000);
});

test('parse CSV: extracts sender phone and message', function () {
    $rows = (new SwishReportParser)->parse(swishCsvContent([swishCsvRow(['avsndnr' => '+46709876543', 'msg' => 'Julbord'])]));

    expect($rows[0]['sender_phone'])->toBe('+46709876543')
        ->and($rows[0]['message'])->toBe('Julbord');
});

test('parse CSV: extracts order reference', function () {
    $rows = (new SwishReportParser)->parse(swishCsvContent([swishCsvRow(['orderref' => 'ORDER-999'])]));

    expect($rows[0]['order_reference'])->toBe('ORDER-999');
});

test('parse CSV: skips rows with fewer than 13 columns', function () {
    $content = swishCsvHeader() . "\n1,2,3";
    $parser  = new SwishReportParser;
    $rows    = $parser->parse($content);

    expect($rows)->toBeEmpty()
        ->and($parser->getSkippedRows())->toHaveCount(1)
        ->and($parser->getSkippedRows()[0]['reason'])->toBe('För få kolumner');
});

test('parse CSV: empty content returns empty array', function () {
    expect((new SwishReportParser)->parse(''))->toBeEmpty();
});

test('parse CSV: skips a row with an unparseable date instead of crashing', function () {
    $content = swishCsvContent([
        swishCsvRow(['radnr' => '1']),
        swishCsvRow(['radnr' => '2', 'valdag' => '']),
    ]);
    $parser  = new SwishReportParser;
    $rows    = $parser->parse($content);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['row_number'])->toBe(1)
        ->and($parser->getSkippedRows())->toHaveCount(1)
        ->and($parser->getSkippedRows()[0])->toBe(['row_number' => 2, 'reason' => 'Ogiltigt eller saknat datum']);
});

test('getSkippedRows resets on each parse() call', function () {
    $parser = new SwishReportParser;
    $parser->parse(swishCsvContent([swishCsvRow(['valdag' => ''])]));
    expect($parser->getSkippedRows())->toHaveCount(1);

    $parser->parse(swishCsvContent());
    expect($parser->getSkippedRows())->toBeEmpty();
});

// ── parse() — TSV ─────────────────────────────────────────────────────────────

test('parse TSV: detects tab-separated format', function () {
    $rows = (new SwishReportParser)->parse(swishTsvContent());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['amount_ore'])->toBe(150000);
});

test('parse TSV: handles comma decimal with spaces as thousands', function () {
    $rows = (new SwishReportParser)->parse(swishTsvContent([12 => '2 500,50']));

    expect($rows[0]['amount_ore'])->toBe(250050);
});

// ── parsePeriod() ─────────────────────────────────────────────────────────────

test('parsePeriod extracts start and end dates', function () {
    $content          = swishCsvContent();
    [$from, $to]      = (new SwishReportParser)->parsePeriod($content);

    expect($from)->toBe('2027-01-01')
        ->and($to)->toBe('2027-06-30');
});

test('parsePeriod works with "Period" keyword', function () {
    $content     = swishTsvContent();
    [$from, $to] = (new SwishReportParser)->parsePeriod($content);

    expect($from)->toBe('2027-01-01')
        ->and($to)->toBe('2027-06-30');
});

test('parsePeriod returns [null, null] when no period found', function () {
    [$from, $to] = (new SwishReportParser)->parsePeriod("* No period here\nheader");

    expect($from)->toBeNull()
        ->and($to)->toBeNull();
});

// ── parseAccountNumber() ──────────────────────────────────────────────────────

test('parseAccountNumber returns Kontonr from first data row', function () {
    $result = (new SwishReportParser)->parseAccountNumber(swishCsvContent([swishCsvRow(['kontonr' => '87654321'])]));

    expect($result)->toBe('87654321');
});

// ── fileHash() ────────────────────────────────────────────────────────────────

test('fileHash is stable and differs for different content', function () {
    $h = SwishReportParser::fileHash('data');
    expect($h)->toBe(SwishReportParser::fileHash('data'))
        ->and($h)->not->toBe(SwishReportParser::fileHash('other'));
});
