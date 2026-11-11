<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\OpeningBalance;
use App\Services\Accounting\Sie4Exporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function sieAccount(string $code, string $type = 'asset'): Account
{
    return Account::firstOrCreate(['code' => $code], [
        'name'      => "Konto {$code}",
        'type'      => $type,
        'is_active' => true,
        'is_group'  => false,
    ]);
}

function sieFiscalYear(string $start = '2027-01-01', string $end = '2027-12-31'): FiscalYear
{
    return FiscalYear::create([
        'name'      => 'Räkenskapsår 2027',
        'starts_at' => $start,
        'ends_at'   => $end,
        'is_locked' => false,
    ]);
}

function sieEntry(FiscalYear $fy, string $date, string $series = 'A', int $number = 1): JournalEntry
{
    return JournalEntry::create([
        'fiscal_year_id'  => $fy->id,
        'series'          => $series,
        'number'          => $number,
        'date'            => $date,
        'description'     => 'Test verifikat',
        'is_locked'       => false,
        'requires_receipt'=> false,
    ]);
}

function sie4Output(FiscalYear $fy): string
{
    return mb_convert_encoding((new Sie4Exporter($fy))->generate(), 'UTF-8', 'ISO-8859-1');
}

// ── Header ────────────────────────────────────────────────────────────────────

test('generate contains required SIE4 header records', function () {
    $fy  = sieFiscalYear();
    $out = sie4Output($fy);

    expect($out)->toContain('#FLAGGA 0')
        ->and($out)->toContain('#FORMAT PC8')
        ->and($out)->toContain('#SIETYP 4')
        ->and($out)->toContain('#VALUTA SEK')
        ->and($out)->toContain('#RAR 0 20270101 20271231');
});

test('generate includes RAR with correct dates', function () {
    $fy  = sieFiscalYear('2026-07-01', '2027-06-30');
    $out = sie4Output($fy);

    expect($out)->toContain('#RAR 0 20260701 20270630');
});

// ── Account definitions ───────────────────────────────────────────────────────

test('generate includes active leaf accounts with KONTO and KTYP', function () {
    $fy = sieFiscalYear();
    sieAccount('1910', 'asset');
    $out = sie4Output($fy);

    expect($out)->toContain('#KONTO 1910')
        ->and($out)->toContain('#KTYP 1910 T');
});

test('KTYP maps account types correctly', function () {
    $fy = sieFiscalYear();
    sieAccount('1000', 'asset');
    sieAccount('2000', 'liability');
    sieAccount('2090', 'equity');
    sieAccount('3000', 'income');
    sieAccount('4000', 'expense');
    $out = sie4Output($fy);

    expect($out)->toContain('#KTYP 1000 T')
        ->and($out)->toContain('#KTYP 2000 S')
        ->and($out)->toContain('#KTYP 2090 S')
        ->and($out)->toContain('#KTYP 3000 I')
        ->and($out)->toContain('#KTYP 4000 K');
});

// ── Journal entries ───────────────────────────────────────────────────────────

test('generate includes VER and TRANS records for journal entries', function () {
    $fy     = sieFiscalYear();
    $a1     = sieAccount('1910', 'asset');
    $a2     = sieAccount('3000', 'income');
    $entry  = sieEntry($fy, '2027-03-15', 'A', 1);

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 100_00],
    ]);

    $out = sie4Output($fy);

    expect($out)->toContain('#VER A 1 20270315')
        ->and($out)->toContain('#TRANS 1910 {} 100.00')
        ->and($out)->toContain('#TRANS 3000 {} -100.00');
});

test('generate uses only current (non-superseded) lines', function () {
    $fy    = sieFiscalYear();
    $a1    = sieAccount('1920', 'asset');
    $a2    = sieAccount('3001', 'income');
    $entry = sieEntry($fy, '2027-03-01', 'A', 1);

    // Superseded old line
    $entry->lines()->create(['account_id' => $a1->id, 'debit' => 999_00, 'credit' => 0, 'superseded_at' => now()]);

    // Current correct lines
    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 100_00],
    ]);

    $out = sie4Output($fy);

    // Should contain the correct amount (100.00), not the superseded 999.00
    expect($out)->toContain('#TRANS 1920 {} 100.00')
        ->and($out)->not->toContain('999.00');
});

// ── Amount formatting ─────────────────────────────────────────────────────────

test('amount formatting converts öre to kronor with period decimal', function () {
    $fy    = sieFiscalYear();
    $a1    = sieAccount('1930', 'asset');
    $a2    = sieAccount('3010', 'income');
    $entry = sieEntry($fy, '2027-04-01');

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 125050, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 125050],
    ]);

    $out = sie4Output($fy);

    expect($out)->toContain('1250.50');
});

test('amount formatting pads cents to two digits', function () {
    $fy    = sieFiscalYear();
    $a1    = sieAccount('1931', 'asset');
    $a2    = sieAccount('3011', 'income');
    $entry = sieEntry($fy, '2027-04-01');

    // 10001 öre = 100 kr + 1 öre = 100.01
    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 10001, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,     'credit' => 10001],
    ]);

    $out = sie4Output($fy);

    expect($out)->toContain('100.01');
});

test('credit lines appear as negative amounts in TRANS', function () {
    $fy    = sieFiscalYear();
    $a1    = sieAccount('1932', 'asset');
    $a2    = sieAccount('3012', 'income');
    $entry = sieEntry($fy, '2027-04-01');

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 200_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 200_00],
    ]);

    $out = sie4Output($fy);

    // Credit line: net = debit-credit = 0-20000 = -20000 öre → -200.00 kr
    expect($out)->toContain('#TRANS 3012 {} -200.00');
});

// ── Balance records ───────────────────────────────────────────────────────────

test('income account uses RES not IB/UB', function () {
    $fy    = sieFiscalYear();
    $a1    = sieAccount('1940', 'asset');
    $a2    = sieAccount('3020', 'income');
    $entry = sieEntry($fy, '2027-05-01');

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 300_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 300_00],
    ]);

    $out = sie4Output($fy);

    expect($out)->toContain('#RES 0 3020')
        ->and($out)->not->toContain('#IB 0 3020')
        ->and($out)->not->toContain('#UB 0 3020');
});

test('expense account uses RES not IB/UB', function () {
    $fy    = sieFiscalYear();
    $a1    = sieAccount('1941', 'asset');
    $a2    = sieAccount('4010', 'expense');
    $entry = sieEntry($fy, '2027-05-01');

    $entry->lines()->createMany([
        ['account_id' => $a2->id, 'debit' => 150_00, 'credit' => 0],
        ['account_id' => $a1->id, 'debit' => 0,      'credit' => 150_00],
    ]);

    $out = sie4Output($fy);

    expect($out)->toContain('#RES 0 4010')
        ->and($out)->not->toContain('#IB 0 4010');
});

test('asset account uses IB and UB', function () {
    $fy    = sieFiscalYear('2027-01-01', '2027-12-31');
    $a1    = sieAccount('1950', 'asset');
    $a2    = sieAccount('3030', 'income');

    // Previous FY with a movement to create an IB
    $prevFy = FiscalYear::create([
        'name'      => 'FY 2026',
        'starts_at' => '2026-01-01',
        'ends_at'   => '2026-12-31',
        'is_locked' => true,
    ]);
    $prevEntry = sieEntry($prevFy, '2026-06-01', 'A', 1);
    $prevEntry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 500_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 500_00],
    ]);

    // Current FY movement
    $entry = sieEntry($fy, '2027-06-01', 'A', 1);
    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 100_00],
    ]);

    $out = sie4Output($fy);

    expect($out)->toContain('#IB 0 1950')
        ->and($out)->toContain('#UB 0 1950');
});

// ── OpeningBalance override ───────────────────────────────────────────────────

test('explicit OpeningBalance overrides computed IB', function () {
    $fy = sieFiscalYear();
    $a  = sieAccount('1960', 'asset');
    $a2 = sieAccount('3040', 'income');

    // Explicit opening balance: 1000.00 kr
    $user = \App\Models\User::factory()->create();
    OpeningBalance::create([
        'fiscal_year_id' => $fy->id,
        'account_id'     => $a->id,
        'amount'         => 100_000,
        'created_by'     => $user->id,
    ]);

    // Current FY movement
    $entry = sieEntry($fy, '2027-06-01', 'A', 1);
    $entry->lines()->createMany([
        ['account_id' => $a->id, 'debit' => 50_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,    'credit' => 50_00],
    ]);

    $out = sie4Output($fy);

    expect($out)->toContain('#IB 0 1960 1000.00');
});

// ── filename ─────────────────────────────────────────────────────────────────

test('filename returns .se extension with sanitized fiscal year name', function () {
    $fy = sieFiscalYear();
    $exporter = new Sie4Exporter($fy);

    expect($exporter->filename())->toEndWith('.se')
        ->and($exporter->filename())->toContain('SIE4_');
});
