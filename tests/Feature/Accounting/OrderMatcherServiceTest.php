<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Shop\Order;
use App\Models\User;
use App\Services\Accounting\OrderMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function omsBankAccount(string $code = '1910'): BankAccount
{
    $ledger = Account::firstOrCreate(['code' => $code], [
        'name' => "Konto {$code}", 'type' => 'asset', 'is_active' => true, 'is_group' => false,
    ]);
    return BankAccount::firstOrCreate(['account_id' => $ledger->id], ['name' => "Bank {$code}"]);
}

function omsBankTx(BankAccount $ba, int $amount, string $ref = '', string $desc = ''): BankTransaction
{
    return BankTransaction::create([
        'bank_account_id'  => $ba->id,
        'amount'           => $amount,
        'booking_date'     => '2027-06-15',
        'transaction_date' => '2027-06-15',
        'description'      => $desc ?: 'BG-inbetalning',
        'reference'        => $ref,
        'status'           => BankTransaction::STATUS_UNMATCHED,
    ]);
}

function omsPendingOrder(int $totalOre, string $ocr): Order
{
    $user = User::factory()->create();
    return Order::create([
        'user_id'           => $user->id,
        'status'            => Order::STATUS_PENDING,
        'payment_method'    => Order::METHOD_BANKGIRO,
        'payment_reference' => 'BG-' . uniqid(),
        'total_ore'         => $totalOre,
        'ocr_number'        => $ocr,
    ]);
}

// ── processImport() ───────────────────────────────────────────────────────────

test('processImport matches by OCR in reference field', function () {
    Event::fake();

    $ba    = omsBankAccount('1910');
    $order = omsPendingOrder(300_00, '123456789');
    $tx    = omsBankTx($ba, 300_00, ref: '123456789');

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(1)
        ->and($tx->fresh()->status)->toBe(BankTransaction::STATUS_MATCHED);
});

test('processImport matches when OCR is embedded in description', function () {
    Event::fake();

    $ba    = omsBankAccount('1911');
    $order = omsPendingOrder(200_00, '987654');
    $tx    = omsBankTx($ba, 200_00, desc: 'Bankgiro 987654 inbetald');

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(1);
});

test('processImport strips non-digits before matching OCR', function () {
    Event::fake();

    $ba    = omsBankAccount('1912');
    $order = omsPendingOrder(150_00, '111222');
    $tx    = omsBankTx($ba, 150_00, ref: 'OCR: 111-222');

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(1);
});

test('processImport rejects when amount does not match', function () {
    Event::fake();

    $ba    = omsBankAccount('1913');
    $order = omsPendingOrder(300_00, '555666');
    $tx    = omsBankTx($ba, 299_00, ref: '555666');

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(0)
        ->and($tx->fresh()->status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

test('processImport skips outgoing transactions', function () {
    Event::fake();

    $ba    = omsBankAccount('1914');
    $order = omsPendingOrder(300_00, '444555');
    $tx    = omsBankTx($ba, -300_00, ref: '444555');

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(0);
});

test('processImport skips already-matched transactions', function () {
    Event::fake();

    $ba    = omsBankAccount('1915');
    $order = omsPendingOrder(300_00, '333444');
    $tx    = omsBankTx($ba, 300_00, ref: '333444');
    $tx->update(['status' => BankTransaction::STATUS_MATCHED]);

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(0);
});

test('processImport does not match same order twice', function () {
    Event::fake();

    $ba    = omsBankAccount('1916');
    $order = omsPendingOrder(300_00, '222333');
    $tx1   = omsBankTx($ba, 300_00, ref: '222333');
    $tx2   = omsBankTx($ba, 300_00, ref: '222333');

    $matched = (new OrderMatcherService)->processImport(collect([$tx1, $tx2]));

    expect($matched)->toBe(1);
});

test('processImport returns 0 when no pending orders exist', function () {
    $ba = omsBankAccount('1917');
    $tx = omsBankTx($ba, 300_00, ref: '123');

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(0);
});

test('processImport skips corporate card transactions', function () {
    Event::fake();

    $ba     = omsBankAccount('1918');
    $order  = omsPendingOrder(300_00, '777888');
    $member = \App\Models\Membership\Member::factory()->create();
    $card   = \App\Models\Accounting\CorporateCard::create([
        'member_id'      => $member->id,
        'card_reference' => 'CARD-OMS-001',
        'is_active'      => true,
    ]);
    $tx = BankTransaction::create([
        'bank_account_id'   => $ba->id,
        'amount'            => 300_00,
        'booking_date'      => '2027-06-15',
        'transaction_date'  => '2027-06-15',
        'description'       => 'BG 777888',
        'status'            => BankTransaction::STATUS_UNMATCHED,
        'corporate_card_id' => $card->id,
    ]);

    $matched = (new OrderMatcherService)->processImport(collect([$tx]));

    expect($matched)->toBe(0);
});
