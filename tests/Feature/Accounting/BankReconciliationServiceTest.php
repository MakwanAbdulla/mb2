<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\BankReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function reconAccount(string $code, string $type = 'asset'): Account
{
    return Account::firstOrCreate(['code' => $code], [
        'name'      => "Konto {$code}",
        'type'      => $type,
        'is_active' => true,
        'is_group'  => false,
    ]);
}

function reconBankAccount(string $code = '1910'): BankAccount
{
    $ledger = reconAccount($code);
    return BankAccount::firstOrCreate(['account_id' => $ledger->id], ['name' => "Bank {$code}"]);
}

function reconFiscalYear(): FiscalYear
{
    static $n = 0;
    return FiscalYear::create([
        'name'      => 'FY Recon ' . (++$n),
        'starts_at' => '2027-01-01',
        'ends_at'   => '2027-12-31',
        'is_locked' => false,
    ]);
}

function reconBankTx(BankAccount $ba, int $amount, string $date = '2027-06-15'): BankTransaction
{
    return BankTransaction::create([
        'bank_account_id'  => $ba->id,
        'amount'           => $amount,
        'booking_date'     => $date,
        'transaction_date' => $date,
        'description'      => 'Test',
        'status'           => BankTransaction::STATUS_UNMATCHED,
    ]);
}

function reconEntry(FiscalYear $fy, BankAccount $ba, int $amount, string $date, bool $incoming): JournalEntry
{
    $counterAccount = reconAccount('3000', 'income');

    $entry = JournalEntry::create([
        'fiscal_year_id'  => $fy->id,
        'series'          => 'A',
        'number'          => rand(1, 9999),
        'date'            => $date,
        'description'     => 'Test verifikat',
        'is_locked'       => false,
        'requires_receipt'=> false,
    ]);

    if ($incoming) {
        // Bank debited (debit = amount), counter credited
        $entry->lines()->createMany([
            ['account_id' => $ba->account_id,      'debit' => $amount,  'credit' => 0],
            ['account_id' => $counterAccount->id,   'debit' => 0,        'credit' => $amount],
        ]);
    } else {
        // Counter debited, bank credited
        $entry->lines()->createMany([
            ['account_id' => $counterAccount->id,   'debit' => $amount,  'credit' => 0],
            ['account_id' => $ba->account_id,        'debit' => 0,        'credit' => $amount],
        ]);
    }

    return $entry;
}

// ── suggest() — basic matching ────────────────────────────────────────────────

test('suggest: returns null when no journal entries match amount', function () {
    $ba = reconBankAccount('1910');
    $tx = reconBankTx($ba, 300_00);

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result)->toBeNull();
});

test('suggest: returns null when bank transaction amount is zero', function () {
    $ba = reconBankAccount('1911');
    $tx = reconBankTx($ba, 0);

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result)->toBeNull();
});

test('suggest: finds matching incoming journal entry', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1912');
    $entry = reconEntry($fy, $ba, 500_00, '2027-06-15', incoming: true);
    $tx    = reconBankTx($ba, 500_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result)->not->toBeNull()
        ->and($result['entry']->id)->toBe($entry->id);
});

test('suggest: finds matching outgoing journal entry', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1913');
    $entry = reconEntry($fy, $ba, 200_00, '2027-06-15', incoming: false);
    $tx    = reconBankTx($ba, -200_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result)->not->toBeNull()
        ->and($result['entry']->id)->toBe($entry->id);
});

test('suggest: does not confuse incoming and outgoing amounts', function () {
    $fy = reconFiscalYear();
    $ba = reconBankAccount('1914');
    reconEntry($fy, $ba, 300_00, '2027-06-15', incoming: false); // outgoing entry
    $tx = reconBankTx($ba, 300_00); // incoming transaction

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result)->toBeNull();
});

// ── Confidence levels ─────────────────────────────────────────────────────────

test('suggest: same date gives high confidence', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1915');
    reconEntry($fy, $ba, 100_00, '2027-06-15', incoming: true);
    $tx = reconBankTx($ba, 100_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result['confidence'])->toBe('high');
});

test('suggest: 2-day gap gives high confidence', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1916');
    reconEntry($fy, $ba, 100_00, '2027-06-13', incoming: true);
    $tx = reconBankTx($ba, 100_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result['confidence'])->toBe('high');
});

test('suggest: 7-day gap gives medium confidence', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1917');
    reconEntry($fy, $ba, 100_00, '2027-06-08', incoming: true);
    $tx = reconBankTx($ba, 100_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result['confidence'])->toBe('medium');
});

test('suggest: 30-day gap gives low confidence', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1918');
    reconEntry($fy, $ba, 100_00, '2027-05-15', incoming: true);
    $tx = reconBankTx($ba, 100_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result['confidence'])->toBe('low');
});

// ── Already claimed entries excluded ──────────────────────────────────────────

test('suggest: excludes journal entries already linked to another transaction', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1919');
    $entry = reconEntry($fy, $ba, 100_00, '2027-06-15', incoming: true);

    // Another transaction already claims this journal entry
    $other = reconBankTx($ba, 100_00, '2027-06-15');
    $other->update(['journal_entry_id' => $entry->id]);

    $tx = reconBankTx($ba, 100_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result)->toBeNull();
});

// ── suggestBatch() ────────────────────────────────────────────────────────────

test('suggestBatch returns keyed results by transaction id', function () {
    $fy = reconFiscalYear();
    $ba = reconBankAccount('1920');
    reconEntry($fy, $ba, 150_00, '2027-06-15', incoming: true);
    $tx1 = reconBankTx($ba, 150_00, '2027-06-15');
    $tx2 = reconBankTx($ba, 999_00, '2027-06-15'); // no matching entry

    $results = (new BankReconciliationService)->suggestBatch(collect([$tx1, $tx2]));

    expect($results)->toHaveKey($tx1->id)
        ->and($results[$tx1->id])->not->toBeNull()
        ->and($results[$tx2->id])->toBeNull();
});

// ── reason string ─────────────────────────────────────────────────────────────

test('suggest: reason says "Exakt belopp, samma datum" for zero-day gap', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1921');
    reconEntry($fy, $ba, 200_00, '2027-06-15', incoming: true);
    $tx    = reconBankTx($ba, 200_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result['reason'])->toContain('samma datum');
});

test('suggest: reason says days for non-zero gap', function () {
    $fy    = reconFiscalYear();
    $ba    = reconBankAccount('1922');
    reconEntry($fy, $ba, 200_00, '2027-06-10', incoming: true);
    $tx    = reconBankTx($ba, 200_00, '2027-06-15');

    $result = (new BankReconciliationService)->suggest($tx);

    expect($result['reason'])->toContain('5 dagar');
});
