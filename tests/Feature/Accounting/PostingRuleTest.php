<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingInboxEmail;
use App\Models\Accounting\AccountingInboxItem;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\PostingRule;
use App\Models\Accounting\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeRuleAccount(string $code = '3000'): Account
{
    static $n = 0;
    return Account::firstOrCreate(['code' => $code], [
        'name' => "Konto {$code}", 'type' => 'income', 'is_active' => true, 'is_group' => false,
    ]);
}

function makeBankAccount(): BankAccount
{
    // A counter, not rand(1, 9): two bank accounts in one test collided 1 time in 9.
    static $n = 0;
    $ledger = Account::create(['code' => (string) (1910 + (++$n % 90)), 'name' => 'Bankledger', 'type' => 'asset', 'is_active' => true, 'is_group' => false]);
    return BankAccount::create(['name' => 'Test Bank', 'account_id' => $ledger->id]);
}

function makeBankTx(array $attrs = []): BankTransaction
{
    $ba = makeBankAccount();
    return BankTransaction::create(array_merge([
        'bank_account_id'  => $ba->id,
        'amount'           => 100_00,
        'booking_date'     => '2027-06-15',
        'transaction_date' => '2027-06-15',
        'description'      => 'Test transaktion',
        'reference'        => null,
        'status'           => BankTransaction::STATUS_UNMATCHED,
    ], $attrs));
}

function makeRule(array $attrs = []): PostingRule
{
    $counter = makeRuleAccount('3' . rand(100, 999));
    return PostingRule::create(array_merge([
        'name'               => 'Test regel',
        'trigger'            => PostingRule::TRIGGER_BANK_TRANSACTION,
        'counter_account_id' => $counter->id,
        'transaction_type'   => PostingRule::TYPE_ANY,
        'is_active'          => true,
        'sort_order'         => 0,
        'series'             => 'A',
        'requires_receipt'   => false,
    ], $attrs));
}

// ── matches() — bank transaction ──────────────────────────────────────────────

test('rule with no filters matches any transaction', function () {
    $rule = makeRule();
    $tx   = makeBankTx(['amount' => 500_00]);

    expect($rule->matches($tx))->toBeTrue();
});

test('rule without counter_account_id never matches', function () {
    $rule = makeRule(['counter_account_id' => null]);
    $tx   = makeBankTx();

    expect($rule->matches($tx))->toBeFalse();
});

test('bank_account_id filter: null matches any account', function () {
    $rule = makeRule(['bank_account_id' => null]);
    $tx   = makeBankTx();

    expect($rule->matches($tx))->toBeTrue();
});

test('bank_account_id filter: mismatch rejects', function () {
    $otherBa = makeBankAccount();
    $rule    = makeRule(['bank_account_id' => $otherBa->id]);
    $tx      = makeBankTx(); // different bank account

    expect($rule->matches($tx))->toBeFalse();
});

test('transaction_type credit rejects outgoing amounts', function () {
    $rule = makeRule(['transaction_type' => PostingRule::TYPE_CREDIT]);
    $tx   = makeBankTx(['amount' => -200_00]);

    expect($rule->matches($tx))->toBeFalse();
});

test('transaction_type credit accepts incoming amounts', function () {
    $rule = makeRule(['transaction_type' => PostingRule::TYPE_CREDIT]);
    $tx   = makeBankTx(['amount' => 200_00]);

    expect($rule->matches($tx))->toBeTrue();
});

test('transaction_type debit rejects incoming amounts', function () {
    $rule = makeRule(['transaction_type' => PostingRule::TYPE_DEBIT]);
    $tx   = makeBankTx(['amount' => 200_00]);

    expect($rule->matches($tx))->toBeFalse();
});

test('transaction_type debit accepts outgoing amounts', function () {
    $rule = makeRule(['transaction_type' => PostingRule::TYPE_DEBIT]);
    $tx   = makeBankTx(['amount' => -200_00]);

    expect($rule->matches($tx))->toBeTrue();
});

test('amount_min_ore rejects amounts below minimum', function () {
    $rule = makeRule(['amount_min_ore' => 500_00]);
    $tx   = makeBankTx(['amount' => 499_00]);

    expect($rule->matches($tx))->toBeFalse();
});

test('amount_min_ore accepts amounts at or above minimum', function () {
    $rule = makeRule(['amount_min_ore' => 500_00]);

    expect($rule->matches(makeBankTx(['amount' => 500_00])))->toBeTrue();
    expect($rule->matches(makeBankTx(['amount' => 501_00])))->toBeTrue();
});

test('amount_max_ore rejects amounts above maximum', function () {
    $rule = makeRule(['amount_max_ore' => 1000_00]);
    $tx   = makeBankTx(['amount' => 1001_00]);

    expect($rule->matches($tx))->toBeFalse();
});

test('amount range uses absolute value for outgoing transactions', function () {
    $rule = makeRule(['amount_min_ore' => 100_00, 'amount_max_ore' => 500_00]);
    $tx   = makeBankTx(['amount' => -300_00]);

    expect($rule->matches($tx))->toBeTrue();
});

test('keyword match is case-insensitive against description', function () {
    $rule = makeRule(['keyword' => 'snabbgross']);
    $tx   = makeBankTx(['description' => 'SNABBGROSS BORÅS AB']);

    expect($rule->matches($tx))->toBeTrue();
});

test('keyword match also checks reference field', function () {
    $rule = makeRule(['keyword' => 'faktura']);
    $tx   = makeBankTx(['description' => 'Betalning', 'reference' => 'FAKTURA-2027-001']);

    expect($rule->matches($tx))->toBeTrue();
});

test('keyword mismatch rejects', function () {
    $rule = makeRule(['keyword' => 'netflix']);
    $tx   = makeBankTx(['description' => 'Spotify AB', 'reference' => null]);

    expect($rule->matches($tx))->toBeFalse();
});

test('valid_from rejects transactions before the validity window', function () {
    $rule = makeRule(['valid_from' => '2027-07-01']);
    $tx   = makeBankTx(['booking_date' => '2027-06-30']);

    expect($rule->matches($tx))->toBeFalse();
});

test('valid_to rejects transactions after the validity window', function () {
    $rule = makeRule(['valid_to' => '2027-06-30']);
    $tx   = makeBankTx(['booking_date' => '2027-07-01']);

    expect($rule->matches($tx))->toBeFalse();
});

test('day_from rejects transactions on day before range', function () {
    $rule = makeRule(['day_from' => 15]);
    $tx   = makeBankTx(['booking_date' => '2027-06-14']);

    expect($rule->matches($tx))->toBeFalse();
});

test('day_to rejects transactions on day after range', function () {
    $rule = makeRule(['day_to' => 10]);
    $tx   = makeBankTx(['booking_date' => '2027-06-11']);

    expect($rule->matches($tx))->toBeFalse();
});

test('day range accepts transactions within range', function () {
    $rule = makeRule(['day_from' => 1, 'day_to' => 5]);
    $tx   = makeBankTx(['booking_date' => '2027-06-03']);

    expect($rule->matches($tx))->toBeTrue();
});

// ── matchesInvoice() ──────────────────────────────────────────────────────────

test('matchesInvoice requires trigger to be ap_invoice', function () {
    $rule = makeRule(['trigger' => PostingRule::TRIGGER_BANK_TRANSACTION]);
    $inv  = Invoice::create(['type' => 'payable', 'total' => 500_00, 'amount_ex_vat' => 500_00, 'invoice_date' => '2027-06-01', 'status' => 'draft', 'invoice_number' => 'INV-001']);

    expect($rule->matchesInvoice($inv))->toBeFalse();
});

test('matchesInvoice with no filters matches any invoice', function () {
    $counter = makeRuleAccount('4' . rand(100, 999));
    $rule    = PostingRule::create([
        'name'               => 'AP regel',
        'trigger'            => PostingRule::TRIGGER_AP_INVOICE,
        'counter_account_id' => $counter->id,
        'is_active'          => true,
        'sort_order'         => 0,
        'series'             => 'A',
        'requires_receipt'   => false,
    ]);
    $inv = Invoice::create(['type' => 'payable', 'total' => 100_00, 'amount_ex_vat' => 100_00, 'invoice_date' => '2027-06-01', 'status' => 'draft', 'invoice_number' => 'INV-002']);

    expect($rule->matchesInvoice($inv))->toBeTrue();
});

test('matchesInvoice vendor_id filter: mismatch rejects', function () {
    $vendor  = Vendor::create(['code' => 'V001', 'name' => 'Test Vendor']);
    $counter = makeRuleAccount('5' . rand(100, 999));
    $rule    = PostingRule::create([
        'name'               => 'AP vendor regel',
        'trigger'            => PostingRule::TRIGGER_AP_INVOICE,
        'vendor_id'          => $vendor->id,
        'counter_account_id' => $counter->id,
        'is_active'          => true,
        'sort_order'         => 0,
        'series'             => 'A',
        'requires_receipt'   => false,
    ]);
    $otherVendor = Vendor::create(['code' => 'V999', 'name' => 'Other Vendor']);
    $inv = Invoice::create(['type' => 'payable', 'total' => 100_00, 'amount_ex_vat' => 100_00, 'invoice_date' => '2027-06-01', 'status' => 'draft', 'vendor_id' => $otherVendor->id, 'invoice_number' => 'INV-003']);

    expect($rule->matchesInvoice($inv))->toBeFalse();
});

test('matchesInvoice keyword matches invoice_number case-insensitively', function () {
    $counter = makeRuleAccount('6' . rand(100, 999));
    $rule    = PostingRule::create([
        'name'               => 'Keyword regel',
        'trigger'            => PostingRule::TRIGGER_AP_INVOICE,
        'keyword'            => 'spotify',
        'counter_account_id' => $counter->id,
        'is_active'          => true,
        'sort_order'         => 0,
        'series'             => 'A',
        'requires_receipt'   => false,
    ]);
    $inv = Invoice::create(['type' => 'payable', 'total' => 100_00, 'amount_ex_vat' => 100_00, 'invoice_date' => '2027-06-01', 'status' => 'draft', 'invoice_number' => 'SPOTIFY-2027-01']);

    expect($rule->matchesInvoice($inv))->toBeTrue();
});

// ── matchesInboxEmail() ───────────────────────────────────────────────────────

function makeInboxEmail(array $attrs = []): AccountingInboxEmail
{
    return AccountingInboxEmail::create(array_merge([
        'from_email'   => 'invoice@vendor.com',
        'subject'      => 'Faktura mars 2027',
        'received_at'  => now(),
        'status'       => 'unprocessed',
    ], $attrs));
}

function makeInboxRule(array $attrs = []): PostingRule
{
    $debit  = makeRuleAccount('2' . rand(100, 999));
    $credit = makeRuleAccount('3' . rand(100, 999));
    return PostingRule::create(array_merge([
        'name'              => 'Inbox regel',
        'trigger'           => PostingRule::TRIGGER_INBOX_EMAIL,
        'debit_account_id'  => $debit->id,
        'credit_account_id' => $credit->id,
        'is_active'         => true,
        'sort_order'        => 0,
        'series'            => 'A',
        'requires_receipt'  => false,
    ], $attrs));
}

test('matchesInboxEmail: no filters matches any email', function () {
    $rule  = makeInboxRule();
    $email = makeInboxEmail();

    expect($rule->matchesInboxEmail($email))->toBeTrue();
});

test('matchesInboxEmail: exact email pattern matches', function () {
    $rule  = makeInboxRule(['from_email_pattern' => 'invoice@vendor.com']);
    $email = makeInboxEmail(['from_email' => 'invoice@vendor.com']);

    expect($rule->matchesInboxEmail($email))->toBeTrue();
});

test('matchesInboxEmail: exact email pattern is case-insensitive', function () {
    $rule  = makeInboxRule(['from_email_pattern' => 'invoice@vendor.com']);
    $email = makeInboxEmail(['from_email' => 'INVOICE@VENDOR.COM']);

    expect($rule->matchesInboxEmail($email))->toBeTrue();
});

test('matchesInboxEmail: @domain suffix matches any address at that domain', function () {
    $rule  = makeInboxRule(['from_email_pattern' => '@vendor.com']);
    $email = makeInboxEmail(['from_email' => 'billing@vendor.com']);

    expect($rule->matchesInboxEmail($email))->toBeTrue();
});

test('matchesInboxEmail: @domain suffix rejects different domain', function () {
    $rule  = makeInboxRule(['from_email_pattern' => '@vendor.com']);
    $email = makeInboxEmail(['from_email' => 'billing@other.com']);

    expect($rule->matchesInboxEmail($email))->toBeFalse();
});

test('matchesInboxEmail: original_from_email checked before from_email', function () {
    $rule  = makeInboxRule(['from_email_pattern' => 'original@vendor.com']);
    $email = makeInboxEmail([
        'from_email'          => 'forwarded@internal.se',
        'original_from_email' => 'original@vendor.com',
    ]);

    expect($rule->matchesInboxEmail($email))->toBeTrue();
});

test('matchesInboxEmail: keyword matches subject case-insensitively', function () {
    $rule  = makeInboxRule(['keyword' => 'faktura']);
    $email = makeInboxEmail(['subject' => 'FAKTURA MARS 2027']);

    expect($rule->matchesInboxEmail($email))->toBeTrue();
});

test('matchesInboxEmail: keyword mismatch rejects', function () {
    $rule  = makeInboxRule(['keyword' => 'kvitto']);
    $email = makeInboxEmail(['subject' => 'Faktura mars 2027']);

    expect($rule->matchesInboxEmail($email))->toBeFalse();
});

// ── matchesInboxItem() ────────────────────────────────────────────────────────

test('matchesInboxItem: keyword matches filename when subject does not', function () {
    $rule  = makeInboxRule(['from_email_pattern' => '@sumup.com', 'keyword' => 'fee_invoice']);
    $email = makeInboxEmail(['from_email' => 'noreply@sumup.com', 'subject' => 'Din SumUp månadsöversikt']);
    $item  = AccountingInboxItem::create([
        'accounting_inbox_email_id' => $email->id,
        'from_email'                => 'noreply@sumup.com',
        'filename'                  => 'SumUp_fee_invoice_2027_03.pdf',
        'path'                      => 'inbox/sumup.pdf',
        'mime_type'                 => 'application/pdf',
        'size_bytes'                => 12345,
        'content_hash'              => sha1('sumup'),
        'status'                    => AccountingInboxItem::STATUS_PENDING,
    ]);

    expect($rule->matchesInboxItem($email, $item))->toBeTrue();
});

test('matchesInboxItem: keyword mismatch on both subject and filename rejects', function () {
    $rule  = makeInboxRule(['keyword' => 'fee_invoice']);
    $email = makeInboxEmail(['subject' => 'Månadsöversikt']);
    $item  = AccountingInboxItem::create([
        'accounting_inbox_email_id' => $email->id,
        'from_email'                => 'noreply@vendor.com',
        'filename'                  => 'payout_report.pdf',
        'path'                      => 'inbox/payout.pdf',
        'mime_type'                 => 'application/pdf',
        'size_bytes'                => 5000,
        'content_hash'              => sha1('payout'),
        'status'                    => AccountingInboxItem::STATUS_PENDING,
    ]);

    expect($rule->matchesInboxItem($email, $item))->toBeFalse();
});
