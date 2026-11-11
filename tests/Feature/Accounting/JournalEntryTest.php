<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAccount(string $code = '1910', string $type = 'asset'): Account
{
    return Account::create([
        'code'      => $code,
        'name'      => "Konto {$code}",
        'type'      => $type,
        'is_active' => true,
        'is_group'  => false,
    ]);
}

function makeFiscalYear(string $start = '2027-01-01', string $end = '2027-12-31', bool $locked = false): FiscalYear
{
    static $n = 0;
    return FiscalYear::create([
        'name'      => 'Test ' . (++$n),
        'starts_at' => $start,
        'ends_at'   => $end,
        'is_locked' => $locked,
    ]);
}

function makeEntry(FiscalYear $fy, string $date = '2027-06-01', string $series = 'A', int $number = 1): JournalEntry
{
    return JournalEntry::create([
        'fiscal_year_id' => $fy->id,
        'series'         => $series,
        'number'         => $number,
        'date'           => $date,
        'description'    => 'Test verifikat',
        'is_locked'      => false,
        'requires_receipt'=> false,
    ]);
}

// ── isBalanced ────────────────────────────────────────────────────────────────

test('isBalanced returns true when debit equals credit and is nonzero', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1910');
    $a2    = makeAccount('3000', 'income');

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 100_00],
    ]);

    expect($entry->isBalanced())->toBeTrue();
});

test('isBalanced returns false when debit does not equal credit', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1911');
    $a2    = makeAccount('3001', 'income');

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 90_00],
    ]);

    expect($entry->isBalanced())->toBeFalse();
});

test('isBalanced returns false when all amounts are zero', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1912');
    $a2    = makeAccount('3002', 'income');

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 0, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0, 'credit' => 0],
    ]);

    expect($entry->isBalanced())->toBeFalse();
});

test('isBalanced only considers current (non-superseded) lines', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1913');
    $a2    = makeAccount('3003', 'income');
    $a3    = makeAccount('3004', 'income');

    // Old superseded line that would unbalance
    $entry->lines()->create(['account_id' => $a3->id, 'debit' => 0, 'credit' => 999_00, 'superseded_at' => now()]);

    // Current balanced lines
    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 50_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,     'credit' => 50_00],
    ]);

    expect($entry->isBalanced())->toBeTrue();
});

// ── updateLines (audit trail) ─────────────────────────────────────────────────

test('updateLines supersedes old lines and creates new ones', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1920');
    $a2    = makeAccount('3010', 'income');
    $a3    = makeAccount('3011', 'income');
    $user  = User::factory()->create();

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 200_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 200_00],
    ]);

    $entry->updateLines([
        ['account_id' => $a1->id, 'debit' => 300_00, 'credit' => 0],
        ['account_id' => $a3->id, 'debit' => 0,      'credit' => 300_00],
    ], $user->id, 'Rättelse av belopp');

    // Old lines are superseded, not deleted
    expect($entry->lines()->whereNotNull('superseded_at')->count())->toBe(2);

    // New lines are current
    $current = $entry->currentLines()->get();
    expect($current)->toHaveCount(2);
    expect($current->sum('debit'))->toBe(300_00);
    expect($current->sum('credit'))->toBe(300_00);
});

test('updateLines records edit metadata on the entry', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1921');
    $a2    = makeAccount('3012', 'income');
    $user  = User::factory()->create();

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 100_00],
    ]);

    $entry->updateLines([
        ['account_id' => $a1->id, 'debit' => 150_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 150_00],
    ], $user->id, 'Justerat belopp');

    $entry->refresh();
    expect($entry->hasBeenEdited())->toBeTrue()
        ->and($entry->edited_by)->toBe($user->id)
        ->and($entry->edit_note)->toBe('Justerat belopp');
});

test('updateLines is atomic — all lines superseded and new ones created together', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1922');
    $a2    = makeAccount('3013', 'income');
    $user  = User::factory()->create();

    $entry->lines()->createMany([
        ['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 100_00],
    ]);

    $entry->updateLines([
        ['account_id' => $a1->id, 'debit' => 200_00, 'credit' => 0],
        ['account_id' => $a2->id, 'debit' => 0,      'credit' => 200_00],
    ], $user->id, 'Ny rättelse');

    // Total lines: 2 old + 2 new = 4
    expect($entry->lines()->count())->toBe(4);
});

// ── currentLines vs supersededLines ──────────────────────────────────────────

test('currentLines excludes superseded lines', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1930');

    $entry->lines()->create(['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0, 'superseded_at' => now()]);
    $entry->lines()->create(['account_id' => $a1->id, 'debit' => 150_00, 'credit' => 0]);

    expect($entry->currentLines()->count())->toBe(1);
    expect($entry->currentLines()->first()->debit)->toBe(150_00);
});

test('supersededLines only returns superseded lines', function () {
    $fy    = makeFiscalYear();
    $entry = makeEntry($fy);
    $a1    = makeAccount('1931');

    $entry->lines()->create(['account_id' => $a1->id, 'debit' => 100_00, 'credit' => 0, 'superseded_at' => now()]);
    $entry->lines()->create(['account_id' => $a1->id, 'debit' => 150_00, 'credit' => 0]);

    expect($entry->supersededLines()->count())->toBe(1);
    expect($entry->supersededLines()->first()->debit)->toBe(100_00);
});

// ── FiscalYear ────────────────────────────────────────────────────────────────

test('containsDate returns true for date within fiscal year', function () {
    $fy = makeFiscalYear('2027-01-01', '2027-12-31');

    expect($fy->containsDate('2027-06-15'))->toBeTrue();
    expect($fy->containsDate('2027-01-01'))->toBeTrue();
    expect($fy->containsDate('2027-12-31'))->toBeTrue();
});

test('containsDate returns false for date outside fiscal year', function () {
    $fy = makeFiscalYear('2027-01-01', '2027-12-31');

    expect($fy->containsDate('2026-12-31'))->toBeFalse();
    expect($fy->containsDate('2028-01-01'))->toBeFalse();
});

test('locked fiscal year flag is stored and retrieved correctly', function () {
    $fy = makeFiscalYear('2026-01-01', '2026-12-31', locked: true);

    expect($fy->is_locked)->toBeTrue();

    $fy->update(['is_locked' => false]);
    expect($fy->fresh()->is_locked)->toBeFalse();
});

// ── Scopes ────────────────────────────────────────────────────────────────────

test('unlocked scope excludes locked entries', function () {
    $fy = makeFiscalYear();
    $e1 = makeEntry($fy, '2027-01-10', 'A', 1);
    $e2 = makeEntry($fy, '2027-01-11', 'A', 2);
    $e1->update(['is_locked' => true]);

    expect(JournalEntry::unlocked()->count())->toBe(1);
    expect(JournalEntry::unlocked()->first()->id)->toBe($e2->id);
});

test('forSeries scope filters by series', function () {
    $fy = makeFiscalYear();
    makeEntry($fy, '2027-01-10', 'A', 1);
    makeEntry($fy, '2027-01-11', 'B', 1);
    makeEntry($fy, '2027-01-12', 'A', 2);

    expect(JournalEntry::forSeries('A')->count())->toBe(2);
    expect(JournalEntry::forSeries('B')->count())->toBe(1);
});
