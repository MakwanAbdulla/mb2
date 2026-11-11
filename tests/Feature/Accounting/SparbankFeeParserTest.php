<?php

use App\Services\Accounting\SparbankFeeParser;

/**
 * Test double that bypasses pdftotext by accepting plain text directly.
 */
class TestableSparbankFeeParser extends SparbankFeeParser
{
    private string $fakeText = '';

    public function setFakeText(string $text): void
    {
        $this->fakeText = $text;
    }

    public function parseFromText(string $text): ?array
    {
        $ref = new \ReflectionClass(SparbankFeeParser::class);

        $parsePeriod = $ref->getMethod('parsePeriod');
        $parsePeriod->setAccessible(true);

        $parseFeeLines = $ref->getMethod('parseFeeLines');
        $parseFeeLines->setAccessible(true);

        $parseTotal = $ref->getMethod('parseTotal');
        $parseTotal->setAccessible(true);

        $period = $parsePeriod->invoke($this, $text);
        if (! $period) {
            return null;
        }

        $lines = $parseFeeLines->invoke($this, $text);
        $total = $parseTotal->invoke($this, $text);

        return [
            'period_start' => $period[0],
            'period_end'   => $period[1],
            'total_ore'    => $total ?? array_sum(array_column($lines, 'amount_ore')),
            'lines'        => $lines,
        ];
    }
}

// ── Period parsing ────────────────────────────────────────────────────────────

test('parsePeriod extracts start and end dates', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = "Pris för perioden  2027-03-01 - 2027-03-31\nSomething else";

    $result = $parser->parseFromText($text);

    expect($result)->not->toBeNull()
        ->and($result['period_start'])->toBe('2027-03-01')
        ->and($result['period_end'])->toBe('2027-03-31');
});

test('parsePeriod returns null when no period header found', function () {
    $parser = new TestableSparbankFeeParser;
    $result = $parser->parseFromText("Ingen period här\ntest");

    expect($result)->toBeNull();
});

// ── Total parsing ─────────────────────────────────────────────────────────────

test('parseTotal extracts Summa in öre', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = "Pris för perioden  2027-03-01 - 2027-03-31\nSumma:  1 234,56";

    $result = $parser->parseFromText($text);

    expect($result['total_ore'])->toBe(123456);
});

test('parseTotal handles whole-number totals', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = "Pris för perioden  2027-03-01 - 2027-03-31\nSumma:  50,00";

    $result = $parser->parseFromText($text);

    expect($result['total_ore'])->toBe(5000);
});

// ── Fee line parsing (Type A — fixed fee) ─────────────────────────────────────

test('parseFeeLines type A: extracts description and agreement number', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = implode("\n", [
        "Pris för perioden  2027-03-01 - 2027-03-31",
        "Swish Handel-ta emot     123 414 04 89     50,00",
        "1234140489",
        "Summa:  50,00",
    ]);

    $result = $parser->parseFromText($text);

    expect($result['lines'])->toHaveCount(1)
        ->and($result['lines'][0]['description'])->toBe('Swish Handel-ta emot')
        ->and($result['lines'][0]['agreement_number'])->toBe('1234140489')
        ->and($result['lines'][0]['amount_ore'])->toBe(5000)
        ->and($result['lines'][0]['count'])->toBeNull();
});

// ── Fee line parsing (Type B — per-transaction fee) ───────────────────────────

test('parseFeeLines type B: extracts count and unit price', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = implode("\n", [
        "Pris för perioden  2027-03-01 - 2027-03-31",
        "Swish Handel-ta emot Swish - anko    antal 67 à pris 3,00",
        "1234140489     123 414 04 89     201,00",
        "Summa:  201,00",
    ]);

    $result = $parser->parseFromText($text);

    expect($result['lines'])->toHaveCount(1)
        ->and($result['lines'][0]['count'])->toBe(67)
        ->and($result['lines'][0]['unit_price_ore'])->toBe(300)
        ->and($result['lines'][0]['amount_ore'])->toBe(20100);
});

// ── Agreement number normalisation ────────────────────────────────────────────

test('13-digit agreement number ending in 003 is normalised to 10 digits', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = implode("\n", [
        "Pris för perioden  2027-03-01 - 2027-03-31",
        "Swish Företag     200,00",
        "1236700413003",
        "Summa:  200,00",
    ]);

    $result = $parser->parseFromText($text);

    expect($result['lines'][0]['agreement_number'])->toBe('1236700413');
});

test('10-digit agreement number is not modified', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = implode("\n", [
        "Pris för perioden  2027-03-01 - 2027-03-31",
        "Swish Handel     50,00",
        "1234140489",
        "Summa:  50,00",
    ]);

    $result = $parser->parseFromText($text);

    expect($result['lines'][0]['agreement_number'])->toBe('1234140489');
});

// ── Multiple fee lines ────────────────────────────────────────────────────────

test('parseFeeLines handles multiple fee lines', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = implode("\n", [
        "Pris för perioden  2027-03-01 - 2027-03-31",
        "Swish Handel-ta emot     123 414 04 89     50,00",
        "1234140489",
        "Swish Handel-ta emot Swish - anko    antal 20 à pris 3,00",
        "1234140489     123 414 04 89     60,00",
        "Summa:  110,00",
    ]);

    $result = $parser->parseFromText($text);

    expect($result['lines'])->toHaveCount(2)
        ->and($result['total_ore'])->toBe(11000);
});

// ── Total fallback when Summa line missing ────────────────────────────────────

test('total falls back to sum of fee lines when Summa missing', function () {
    $parser = new TestableSparbankFeeParser;
    $text   = implode("\n", [
        "Pris för perioden  2027-03-01 - 2027-03-31",
        "Swish Handel     75,00",
        "1234140489",
    ]);

    $result = $parser->parseFromText($text);

    expect($result['total_ore'])->toBe(7500);
});
