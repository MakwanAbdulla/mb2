<?php

use App\Services\BgMaxParser;

// ── Helpers ───────────────────────────────────────────────────────────────────

function parser(): BgMaxParser
{
    return new BgMaxParser();
}

function bgMaxLine(string $ref, int $amountOre, string $date = '20250315', string $sender = 'Testperson AB'): string
{
    $bgcNo  = str_pad('1234567890', 10);
    $refPad = str_pad($ref, 25);
    $amt    = str_pad((string) $amountOre, 12, '0', STR_PAD_LEFT);

    return '00header' . "\n"
        . '01' . $bgcNo . $refPad . $amt . $date . $sender . "\n";
}

function bgMaxContent(array $lines): string
{
    $header = '00BGMAX               0000001';
    return $header . "\n" . implode("\n", $lines);
}

function camtXml(array $entries): string
{
    $entryXml = '';
    foreach ($entries as $e) {
        $ref = $e['ref'] ?? 'REF123';
        $amt = $e['amount'] ?? '250.00';
        $date = $e['date'] ?? '2025-03-15';
        $indicator = $e['indicator'] ?? 'CRDT';
        $sender = $e['sender'] ?? 'Anna Svensson';
        $unstructured = $e['unstructured'] ?? false;

        $rmtInf = $unstructured
            ? "<Ustrd>{$ref}</Ustrd>"
            : "<Strd><CdtrRefInf><Ref>{$ref}</Ref></CdtrRefInf></Strd>";

        $entryXml .= "
        <Ntry>
            <Amt>{$amt}</Amt>
            <CdtDbtInd>{$indicator}</CdtDbtInd>
            <BookgDt><Dt>{$date}</Dt></BookgDt>
            <NtryDtls>
                <TxDtls>
                    <RmtInf>{$rmtInf}</RmtInf>
                    <RltdPties><Dbtr><Nm>{$sender}</Nm></Dbtr></RltdPties>
                </TxDtls>
            </NtryDtls>
        </Ntry>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>
<Document>
    <BkToCstmrDbtCdtNtfctn>
        <Ntfctn>' . $entryXml . '</Ntfctn>
    </BkToCstmrDbtCdtNtfctn>
</Document>';
}

// ── BGMax format ──────────────────────────────────────────────────────────────

test('parses valid BGMax TK01 line', function () {
    $content = bgMaxContent([
        '01' . str_pad('1234567890', 10) . str_pad('OCR12345', 25) . str_pad('25000', 12, '0', STR_PAD_LEFT) . '20250315' . 'Kalle Svensson',
    ]);

    $rows = parser()->parse($content);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('OCR12345')
        ->and($rows[0]['amountOre'])->toBe(25000)
        ->and($rows[0]['date'])->toBe('2025-03-15')
        ->and($rows[0]['sender'])->toBe('Kalle Svensson');
});

test('skips BGMax lines that are not TK01', function () {
    $content = bgMaxContent([
        '05' . str_repeat('X', 70), // not TK01
        '01' . str_pad('1234567890', 10) . str_pad('REF1', 25) . str_pad('10000', 12, '0', STR_PAD_LEFT) . '20250315',
    ]);

    $rows = parser()->parse($content);

    expect($rows)->toHaveCount(1);
});

test('skips BGMax lines shorter than 57 chars', function () {
    $content = bgMaxContent(['01short']);

    $rows = parser()->parse($content);

    expect($rows)->toBeEmpty();
});

test('skips BGMax lines with zero amount', function () {
    $content = bgMaxContent([
        '01' . str_pad('1234567890', 10) . str_pad('REF1', 25) . str_pad('0', 12, '0', STR_PAD_LEFT) . '20250315',
    ]);

    $rows = parser()->parse($content);

    expect($rows)->toBeEmpty();
});

test('parses multiple BGMax TK01 lines', function () {
    $rows2 = [
        '01' . str_pad('1234567890', 10) . str_pad('REF001', 25) . str_pad('10000', 12, '0', STR_PAD_LEFT) . '20250101',
        '01' . str_pad('1234567890', 10) . str_pad('REF002', 25) . str_pad('20000', 12, '0', STR_PAD_LEFT) . '20250202',
    ];

    $rows = parser()->parse(bgMaxContent($rows2));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['reference'])->toBe('REF001')
        ->and($rows[1]['reference'])->toBe('REF002');
});

// ── camt.054 XML ──────────────────────────────────────────────────────────────

test('parses camt.054 XML structured OCR reference', function () {
    $rows = parser()->parse(camtXml([
        ['ref' => 'OCR99', 'amount' => '250.00', 'date' => '2025-03-15', 'sender' => 'Anna Svensson'],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('OCR99')
        ->and($rows[0]['amountOre'])->toBe(25000)
        ->and($rows[0]['date'])->toBe('2025-03-15')
        ->and($rows[0]['sender'])->toBe('Anna Svensson');
});

test('parses camt.054 XML unstructured reference', function () {
    $rows = parser()->parse(camtXml([
        ['ref' => 'FREETEXT', 'unstructured' => true, 'amount' => '100.00'],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('FREETEXT');
});

test('skips camt.054 debit entries', function () {
    $rows = parser()->parse(camtXml([
        ['ref' => 'CR1', 'indicator' => 'CRDT'],
        ['ref' => 'DB1', 'indicator' => 'DBIT'],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('CR1');
});

test('skips camt.054 entries with no reference', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
<Document>
    <BkToCstmrDbtCdtNtfctn><Ntfctn>
        <Ntry>
            <Amt>100.00</Amt>
            <CdtDbtInd>CRDT</CdtDbtInd>
            <BookgDt><Dt>2025-03-15</Dt></BookgDt>
            <NtryDtls><TxDtls><RmtInf></RmtInf></TxDtls></NtryDtls>
        </Ntry>
    </Ntfctn></BkToCstmrDbtCdtNtfctn>
</Document>';

    $rows = parser()->parse($xml);

    expect($rows)->toBeEmpty();
});

test('throws InvalidArgumentException for malformed XML', function () {
    expect(fn () => parser()->parse('<not valid xml <<<'))
        ->toThrow(\InvalidArgumentException::class);
});

// ── CSV format ────────────────────────────────────────────────────────────────

test('parses semicolon CSV with standard Swedish headers', function () {
    $csv = "datum;referens;belopp;avsändare\n2025-03-15;REF001;250,00;Anna Svensson";

    $rows = parser()->parse($csv);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('REF001')
        ->and($rows[0]['amountOre'])->toBe(25000)
        ->and($rows[0]['date'])->toBe('2025-03-15')
        ->and($rows[0]['sender'])->toBe('Anna Svensson');
});

test('parses CSV with dot decimal separator', function () {
    $csv = "datum;referens;belopp\n2025-03-15;REF001;250.00";

    $rows = parser()->parse($csv);

    expect($rows[0]['amountOre'])->toBe(25000);
});

test('parses CSV amount with thousands separator space', function () {
    $csv = "datum;referens;belopp\n2025-03-15;REF001;1 250,00";

    $rows = parser()->parse($csv);

    expect($rows[0]['amountOre'])->toBe(125000);
});

test('parses CSV amount with kr suffix', function () {
    $csv = "datum;referens;belopp\n2025-03-15;REF001;250,00 kr";

    $rows = parser()->parse($csv);

    expect($rows[0]['amountOre'])->toBe(25000);
});

test('parses CSV with comma separator', function () {
    $csv = "datum,referens,belopp\n2025-03-15,REF001,250.00";

    $rows = parser()->parse($csv);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('REF001');
});

test('skips CSV rows with empty reference or amount', function () {
    $csv = "datum;referens;belopp\n2025-03-15;;250,00\n2025-03-15;REF002;\n2025-03-15;REF003;150,00";

    $rows = parser()->parse($csv);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('REF003');
});

test('skips CSV rows with zero amount', function () {
    $csv = "datum;referens;belopp\n2025-03-15;REF001;0,00";

    $rows = parser()->parse($csv);

    expect($rows)->toBeEmpty();
});

test('finds header row even when preceded by metadata rows', function () {
    $csv = "Rapport SEB 2025-03-15\nFilexport version 3\ndatum;referens;belopp\n2025-03-15;REF001;100,00";

    $rows = parser()->parse($csv);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reference'])->toBe('REF001');
});

test('throws InvalidArgumentException when no recognisable header found', function () {
    $csv = "col1;col2;col3\nval1;val2;val3";

    expect(fn () => parser()->parse($csv))->toThrow(\InvalidArgumentException::class);
});

test('throws InvalidArgumentException for binary XLS content', function () {
    $xls = "\xD0\xCF\x11\xE0fake excel binary";

    expect(fn () => parser()->parse($xls))->toThrow(\InvalidArgumentException::class);
});

test('throws InvalidArgumentException for binary XLSX content', function () {
    $xlsx = "PK\x03\x04fake xlsx zip";

    expect(fn () => parser()->parse($xlsx))->toThrow(\InvalidArgumentException::class);
});

// ── Encoding ──────────────────────────────────────────────────────────────────

test('handles UTF-8 BOM at start of file', function () {
    $csv = "\xEF\xBB\xBFdatum;referens;belopp\n2025-03-15;REF001;100,00";

    $rows = parser()->parse($csv);

    expect($rows)->toHaveCount(1);
});

test('handles ISO-8859-1 encoded content', function () {
    $csv = mb_convert_encoding("datum;referens;belopp\n2025-03-15;REF001;100,00", 'ISO-8859-1', 'UTF-8');

    $rows = parser()->parse($csv);

    expect($rows)->toHaveCount(1);
});

// ── Date formats ──────────────────────────────────────────────────────────────

test('parses date in slash-separated d/m/Y format', function () {
    $csv = "datum;referens;belopp\n15/03/2025;REF001;100,00";

    $rows = parser()->parse($csv);

    expect($rows[0]['date'])->toBe('2025-03-15');
});

test('parses date in d.m.Y format', function () {
    $csv = "datum;referens;belopp\n15.03.2025;REF001;100,00";

    $rows = parser()->parse($csv);

    expect($rows[0]['date'])->toBe('2025-03-15');
});

test('falls back to today for unparseable date', function () {
    $csv = "datum;referens;belopp\nnodate;REF001;100,00";

    $rows = parser()->parse($csv);

    expect($rows[0]['date'])->toBe(now()->toDateString());
});
