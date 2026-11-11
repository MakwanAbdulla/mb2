<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\User;
use App\Services\Accounting\OrderJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function ojsAccount(string $code, string $type = 'asset'): Account
{
    return Account::firstOrCreate(['code' => $code], [
        'name'      => "Konto {$code}",
        'type'      => $type,
        'is_active' => true,
        'is_group'  => false,
    ]);
}

function ojsFiscalYear(string $start = '2027-01-01', string $end = '2027-12-31'): FiscalYear
{
    static $n = 0;
    return FiscalYear::create([
        'name'      => 'FY OJS ' . (++$n),
        'starts_at' => $start,
        'ends_at'   => $end,
        'is_locked' => false,
    ]);
}

function ojsOrder(array $attrs = []): Order
{
    $user = User::factory()->create();
    return Order::create(array_merge([
        'user_id'           => $user->id,
        'status'            => Order::STATUS_PAID,
        'payment_method'    => Order::METHOD_STRIPE,
        'payment_reference' => 'pi_' . uniqid(),
        'total_ore'         => 100_00,
        'paid_at'           => '2027-06-15',
    ], $attrs));
}

function ojsLine(Order $order, string $category, int $amount): OrderLine
{
    return OrderLine::create([
        'order_id'       => $order->id,
        'category'       => $category,
        'description'    => ucfirst($category),
        'quantity'       => 1,
        'unit_price_ore' => $amount,
        'line_total_ore' => $amount,
    ]);
}

function seedPaymentAccounts(): void
{
    ojsAccount('1935', 'asset'); // Stripe
    ojsAccount('1931', 'asset'); // Swish
    ojsAccount('1933', 'asset'); // Bankgiro
}

function seedCategoryAccounts(): void
{
    ojsAccount('3080', 'income'); // Membership
    ojsAccount('3050', 'income'); // Event
    ojsAccount('3021', 'income'); // Rental
    ojsAccount('3092', 'income'); // Tag
    ojsAccount('3001', 'income'); // Shop
}

// ── bookOrder — basic cases ───────────────────────────────────────────────────

test('bookOrder creates balanced journal entry for a Stripe membership order', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $order = ojsOrder(['payment_method' => Order::METHOD_STRIPE, 'total_ore' => 300_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 300_00);

    $entry = (new OrderJournalService)->bookOrder($order);

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect($entry->series)->toBe('U');
});

test('bookOrder debits Stripe account 1935', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $order = ojsOrder(['payment_method' => Order::METHOD_STRIPE, 'total_ore' => 150_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 150_00);

    $entry = (new OrderJournalService)->bookOrder($order);

    $debitLine = $entry->currentLines()->where('debit', '>', 0)->first();
    $stripeAccount = Account::where('code', '1935')->first();
    expect($debitLine->account_id)->toBe($stripeAccount->id);
});

test('bookOrder debits Swish account 1931', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $order = ojsOrder(['payment_method' => Order::METHOD_SWISH, 'total_ore' => 200_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 200_00);

    $entry = (new OrderJournalService)->bookOrder($order);

    $debitLine = $entry->currentLines()->where('debit', '>', 0)->first();
    $swishAccount = Account::where('code', '1931')->first();
    expect($debitLine->account_id)->toBe($swishAccount->id);
});

test('bookOrder debits Bankgiro account 1933', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $order = ojsOrder(['payment_method' => Order::METHOD_BANKGIRO, 'total_ore' => 250_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 250_00);

    $entry = (new OrderJournalService)->bookOrder($order);

    $debitLine = $entry->currentLines()->where('debit', '>', 0)->first();
    $bgAccount = Account::where('code', '1933')->first();
    expect($debitLine->account_id)->toBe($bgAccount->id);
});

test('bookOrder credits membership account 3080 for membership lines', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $order = ojsOrder(['total_ore' => 300_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 300_00);

    $entry = (new OrderJournalService)->bookOrder($order);

    $creditLines = $entry->currentLines()->where('credit', '>', 0)->get();
    $membershipAccount = Account::where('code', '3080')->first();
    expect($creditLines->first()->account_id)->toBe($membershipAccount->id);
});

test('bookOrder creates separate credit lines for different categories', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $order = ojsOrder(['total_ore' => 500_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 300_00);
    ojsLine($order, OrderLine::CATEGORY_SHOP, 200_00);

    $entry = (new OrderJournalService)->bookOrder($order);

    // 1 debit + 2 credit lines
    expect($entry->currentLines()->count())->toBe(3);
    expect($entry->isBalanced())->toBeTrue();
});

test('bookOrder returns null for free order', function () {
    ojsFiscalYear();

    $order = ojsOrder(['payment_method' => Order::METHOD_FREE, 'total_ore' => 0]);

    $result = (new OrderJournalService)->bookOrder($order);

    expect($result)->toBeNull();
});

test('bookOrder returns null for manual order', function () {
    ojsFiscalYear();

    $order = ojsOrder(['payment_method' => Order::METHOD_MANUAL, 'total_ore' => 100_00]);
    // No journal entry expected

    $result = (new OrderJournalService)->bookOrder($order);

    expect($result)->toBeNull();
});

test('bookOrder returns null when no open fiscal year', function () {
    seedPaymentAccounts();
    seedCategoryAccounts();
    // No FY created

    $order = ojsOrder(['paid_at' => now()]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 100_00);

    $result = (new OrderJournalService)->bookOrder($order);

    expect($result)->toBeNull();
});

test('bookOrder returns null when payment account not in chart', function () {
    ojsFiscalYear();
    seedCategoryAccounts();
    // Payment accounts NOT seeded

    $order = ojsOrder(['payment_method' => Order::METHOD_STRIPE, 'total_ore' => 100_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 100_00);

    $result = (new OrderJournalService)->bookOrder($order);

    expect($result)->toBeNull();
});

test('bookOrder uses sequential numbering in U series', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $o1 = ojsOrder(['payment_reference' => 'pi_1', 'total_ore' => 100_00]);
    ojsLine($o1, OrderLine::CATEGORY_MEMBERSHIP, 100_00);
    $o2 = ojsOrder(['payment_reference' => 'pi_2', 'total_ore' => 200_00]);
    ojsLine($o2, OrderLine::CATEGORY_MEMBERSHIP, 200_00);

    $svc = new OrderJournalService;
    $e1  = $svc->bookOrder($o1);
    $e2  = $svc->bookOrder($o2);

    expect($e1->number)->toBe(1)
        ->and($e2->number)->toBe(2);
});

test('bookOrder links journal entry to order', function () {
    ojsFiscalYear();
    seedPaymentAccounts();
    seedCategoryAccounts();

    $order = ojsOrder(['total_ore' => 100_00]);
    ojsLine($order, OrderLine::CATEGORY_MEMBERSHIP, 100_00);

    $entry = (new OrderJournalService)->bookOrder($order);

    expect($order->fresh()->journal_entry_id)->toBe($entry->id);
});
