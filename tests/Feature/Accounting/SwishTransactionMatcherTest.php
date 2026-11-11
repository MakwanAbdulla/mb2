<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\SwishTransaction;
use App\Services\Accounting\SwishTransactionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function swishMatchBankAccount(string $code = '1910'): BankAccount
{
    $ledger = Account::firstOrCreate(['code' => $code], [
        'name' => "Konto {$code}", 'type' => 'asset', 'is_active' => true, 'is_group' => false,
    ]);
    return BankAccount::firstOrCreate(['account_id' => $ledger->id], ['name' => "Bank {$code}"]);
}

function swishMatchBankTx(BankAccount $ba, int $amount, string $date, string $phone): BankTransaction
{
    return BankTransaction::create([
        'bank_account_id'  => $ba->id,
        'amount'           => $amount,
        'booking_date'     => $date,
        'transaction_date' => $date,
        'description'      => "Swish {$phone}",
        'status'           => BankTransaction::STATUS_UNMATCHED,
    ]);
}

function swishRow(array $overrides = []): array
{
    return array_merge([
        'row_number'       => 1,
        'booking_date'     => '2027-06-15',
        'transaction_date' => '2027-06-15',
        'value_date'       => '2027-06-15',
        'swish_number'     => '1231234567',
        'sender_phone'     => '+46701234567',
        'sender_name'      => 'Kalle Johansson',
        'message'          => 'Avgift',
        'transaction_time' => '14:30',
        'amount_ore'       => 300_00,
        'order_reference'  => '',
    ], $overrides);
}

// ── import() — basic ──────────────────────────────────────────────────────────

test('import creates a SwishImport record with row_count', function () {
    $ba = swishMatchBankAccount('1910');

    $import = (new SwishTransactionMatcher)->import(
        $ba->id,
        rows: [swishRow(), swishRow(['row_number' => 2])],
        fileHash: 'abc123',
        filename: 'swish_2027.csv',
        periodFrom: '2027-06-01',
        periodTo: '2027-06-30',
    );

    expect($import->row_count)->toBe(2)
        ->and($import->bank_account_id)->toBe($ba->id)
        ->and($import->filename)->toBe('swish_2027.csv');
});

test('import creates SwishTransaction rows', function () {
    $ba = swishMatchBankAccount('1911');

    (new SwishTransactionMatcher)->import($ba->id, [swishRow()], 'hash1', 'f.csv', null, null);

    expect(SwishTransaction::count())->toBe(1);
});

test('import skips duplicate rows based on import_hash', function () {
    $ba  = swishMatchBankAccount('1912');
    $row = swishRow();

    (new SwishTransactionMatcher)->import($ba->id, [$row], 'hash_dup_1', 'f1.csv', null, null);
    (new SwishTransactionMatcher)->import($ba->id, [$row], 'hash_dup_2', 'f2.csv', null, null);

    expect(SwishTransaction::count())->toBe(1);
});

test('import links matching bank transaction', function () {
    $ba = swishMatchBankAccount('1913');
    $tx = swishMatchBankTx($ba, 300_00, '2027-06-15', '+46701234567');

    $import = (new SwishTransactionMatcher)->import(
        $ba->id,
        [swishRow(['amount_ore' => 300_00, 'sender_phone' => '+46701234567', 'value_date' => '2027-06-15'])],
        'hash_link',
        'f.csv',
        null,
        null,
    );

    expect($import->matched_count)->toBe(1);
    $st = SwishTransaction::first();
    expect($st->bank_transaction_id)->toBe($tx->id);
});

test('import does not link when phone does not match description', function () {
    $ba = swishMatchBankAccount('1914');
    swishMatchBankTx($ba, 300_00, '2027-06-15', '+46700000000'); // different phone

    (new SwishTransactionMatcher)->import(
        $ba->id,
        [swishRow(['amount_ore' => 300_00, 'sender_phone' => '+46701234567'])],
        'hash_nomatch',
        'f.csv',
        null,
        null,
    );

    $st = SwishTransaction::first();
    expect($st->bank_transaction_id)->toBeNull();
});

test('import does not link when amount differs', function () {
    $ba = swishMatchBankAccount('1915');
    swishMatchBankTx($ba, 200_00, '2027-06-15', '+46701234567');

    (new SwishTransactionMatcher)->import(
        $ba->id,
        [swishRow(['amount_ore' => 300_00])],
        'hash_amtdiff',
        'f.csv',
        null,
        null,
    );

    expect(SwishTransaction::first()->bank_transaction_id)->toBeNull();
});

test('import rolls back entirely when a row fails partway through', function () {
    $ba = swishMatchBankAccount('1918');

    // transaction_time must differ from row 1's, otherwise the two rows hash
    // identically and row 2 gets silently skipped as a duplicate before the
    // missing value_date is ever reached.
    $badRow = swishRow(['row_number' => 2, 'transaction_time' => '15:00']);
    unset($badRow['value_date']);

    expect(fn () => (new SwishTransactionMatcher)->import(
        $ba->id,
        [swishRow(['row_number' => 1]), $badRow],
        'hash_rollback',
        'f.csv',
        null,
        null,
    ))->toThrow(\ErrorException::class);

    // Row 1 succeeded before row 2 blew up — both must roll back together,
    // otherwise the file_hash on the (partially created) SwishImport would
    // block this exact file from ever being re-imported.
    expect(SwishTransaction::count())->toBe(0)
        ->and(\App\Models\Accounting\SwishImport::where('file_hash', 'hash_rollback')->exists())->toBeFalse();
});

// ── rematch() ─────────────────────────────────────────────────────────────────

test('rematch links previously unmatched SwishTransactions to new bank transactions', function () {
    $ba = swishMatchBankAccount('1916');

    // Import without any matching bank tx
    (new SwishTransactionMatcher)->import(
        $ba->id,
        [swishRow(['amount_ore' => 150_00, 'sender_phone' => '+46709999999', 'value_date' => '2027-06-20'])],
        'hash_rematch',
        'f.csv',
        null,
        null,
    );

    expect(SwishTransaction::first()->bank_transaction_id)->toBeNull();

    // Now a bank tx appears
    swishMatchBankTx($ba, 150_00, '2027-06-20', '+46709999999');

    $matched = (new SwishTransactionMatcher)->rematch($ba->id);

    expect($matched)->toBe(1)
        ->and(SwishTransaction::first()->bank_transaction_id)->not->toBeNull();
});

test('rematch returns 0 when all rows already matched', function () {
    $ba = swishMatchBankAccount('1917');
    $tx = swishMatchBankTx($ba, 100_00, '2027-06-15', '+46701111111');

    (new SwishTransactionMatcher)->import(
        $ba->id,
        [swishRow(['amount_ore' => 100_00, 'sender_phone' => '+46701111111'])],
        'hash_already',
        'f.csv',
        null,
        null,
    );

    $matched = (new SwishTransactionMatcher)->rematch($ba->id);

    expect($matched)->toBe(0);
});
