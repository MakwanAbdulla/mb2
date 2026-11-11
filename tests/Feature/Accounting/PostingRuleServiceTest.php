<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingInboxEmail;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\PostingRule;
use App\Services\Accounting\PostingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function svcAccount(string $code, string $type = 'asset'): Account
{
    return Account::firstOrCreate(['code' => $code], [
        'name'      => "Konto {$code}",
        'type'      => $type,
        'is_active' => true,
        'is_group'  => false,
    ]);
}

function svcBankAccount(string $code = '1910'): BankAccount
{
    $ledger = svcAccount($code);
    return BankAccount::firstOrCreate(['account_id' => $ledger->id], ['name' => "Bank {$code}"]);
}

function svcFiscalYear(string $start = '2027-01-01', string $end = '2027-12-31'): FiscalYear
{
    static $n = 0;
    return FiscalYear::create([
        'name'      => 'FY ' . (++$n),
        'starts_at' => $start,
        'ends_at'   => $end,
        'is_locked' => false,
    ]);
}

function svcBankTx(BankAccount $ba, array $attrs = []): BankTransaction
{
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

function svcRule(BankAccount $ba, Account $counter, array $attrs = []): PostingRule
{
    return PostingRule::create(array_merge([
        'name'               => 'Test regel',
        'trigger'            => PostingRule::TRIGGER_BANK_TRANSACTION,
        'bank_account_id'    => null,
        'counter_account_id' => $counter->id,
        'transaction_type'   => PostingRule::TYPE_ANY,
        'is_active'          => true,
        'sort_order'         => 0,
        'series'             => 'A',
        'requires_receipt'   => false,
    ], $attrs));
}

// ── applyAll — basic posting ──────────────────────────────────────────────────

test('applyAll posts matching transaction and returns posted count', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1910');
    $counter = svcAccount('3000', 'income');
    svcRule($ba, $counter);
    svcBankTx($ba, ['amount' => 500_00, 'booking_date' => '2027-03-01']);

    $result = (new PostingRuleService)->applyAll();

    expect($result['posted'])->toBe(1)
        ->and($result['skipped'])->toBe(0)
        ->and($result['errors'])->toBeEmpty();
});

test('applyAll skips transaction when no rule matches', function () {
    svcFiscalYear();
    $ba      = svcBankAccount('1911');
    $counter = svcAccount('3001', 'income');
    svcRule($ba, $counter, ['keyword' => 'spotify']);
    svcBankTx($ba, ['description' => 'Netflix AB', 'booking_date' => '2027-03-01']);

    $result = (new PostingRuleService)->applyAll();

    expect($result['posted'])->toBe(0)
        ->and($result['skipped'])->toBe(1);
});

test('applyAll returns error when no open fiscal year', function () {
    $ba      = svcBankAccount('1912');
    $counter = svcAccount('3002', 'income');
    svcRule($ba, $counter);
    svcBankTx($ba, ['booking_date' => '2027-03-01']); // No FY created

    $result = (new PostingRuleService)->applyAll();

    expect($result['errors'])->not->toBeEmpty();
});

test('applyAll first-match-wins: higher sort_order rule is ignored', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1913');
    $c1      = svcAccount('3010', 'income');
    $c2      = svcAccount('3011', 'income');
    svcRule($ba, $c1, ['sort_order' => 0]);
    svcRule($ba, $c2, ['sort_order' => 10]);
    svcBankTx($ba, ['booking_date' => '2027-03-01']);

    (new PostingRuleService)->applyAll();

    // Only one journal entry created, not two
    expect(JournalEntry::count())->toBe(1);

    // The entry's first credit line should use c1, not c2
    $entry = JournalEntry::first();
    $creditLine = $entry->currentLines()->where('credit', '>', 0)->first();
    expect($creditLine->account_id)->toBe($c1->id);
});

test('applyAll scoped to bankAccountId only processes that accounts transactions', function () {
    $fy   = svcFiscalYear();
    $ba1  = svcBankAccount('1914');
    $ba2  = svcBankAccount('1915');
    $c    = svcAccount('3020', 'income');
    svcRule($ba1, $c);
    svcBankTx($ba1, ['booking_date' => '2027-03-01']);
    svcBankTx($ba2, ['booking_date' => '2027-03-01']); // different bank account

    $result = (new PostingRuleService)->applyAll($ba1->id);

    expect($result['posted'])->toBe(1);
    // ba2 transaction is still unmatched
    expect(BankTransaction::where('bank_account_id', $ba2->id)->where('status', BankTransaction::STATUS_UNMATCHED)->count())->toBe(1);
});

test('applyAll inactive rules are ignored', function () {
    svcFiscalYear();
    $ba      = svcBankAccount('1916');
    $counter = svcAccount('3030', 'income');
    svcRule($ba, $counter, ['is_active' => false]);
    svcBankTx($ba, ['booking_date' => '2027-03-01']);

    $result = (new PostingRuleService)->applyAll();

    // No active rules → service returns early with skipped=0
    expect($result['posted'])->toBe(0)
        ->and($result['skipped'])->toBe(0);
});

// ── post() — journal entry creation ──────────────────────────────────────────

test('post creates balanced journal entry with correct debit/credit for incoming', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1917');
    $counter = svcAccount('3040', 'income');
    $rule    = svcRule($ba, $counter);
    $tx      = svcBankTx($ba, ['amount' => 300_00, 'booking_date' => '2027-04-01']);

    $entry = (new PostingRuleService)->post($tx, $rule);

    expect($entry->isBalanced())->toBeTrue();

    $debitLine  = $entry->currentLines()->where('debit', '>', 0)->first();
    $creditLine = $entry->currentLines()->where('credit', '>', 0)->first();

    // Incoming: bank debited, counter credited
    expect($debitLine->account_id)->toBe($ba->account_id)
        ->and($creditLine->account_id)->toBe($counter->id)
        ->and($debitLine->debit)->toBe(300_00)
        ->and($creditLine->credit)->toBe(300_00);
});

test('post creates balanced journal entry with correct debit/credit for outgoing', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1918');
    $counter = svcAccount('3041', 'income');
    $rule    = svcRule($ba, $counter);
    $tx      = svcBankTx($ba, ['amount' => -200_00, 'booking_date' => '2027-04-01']);

    $entry = (new PostingRuleService)->post($tx, $rule);

    expect($entry->isBalanced())->toBeTrue();

    $debitLine  = $entry->currentLines()->where('debit', '>', 0)->first();
    $creditLine = $entry->currentLines()->where('credit', '>', 0)->first();

    // Outgoing: counter debited, bank credited
    expect($debitLine->account_id)->toBe($counter->id)
        ->and($creditLine->account_id)->toBe($ba->account_id);
});

test('post sets transaction status to posted and links journal entry', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1919');
    $counter = svcAccount('3042', 'income');
    $rule    = svcRule($ba, $counter);
    $tx      = svcBankTx($ba, ['booking_date' => '2027-04-01']);

    $entry = (new PostingRuleService)->post($tx, $rule);

    $tx->refresh();
    expect($tx->status)->toBe(BankTransaction::STATUS_POSTED)
        ->and($tx->journal_entry_id)->toBe($entry->id);
});

test('post uses sequential numbering within series', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1920');
    $counter = svcAccount('3043', 'income');
    $rule    = svcRule($ba, $counter, ['series' => 'B']);
    $tx1     = svcBankTx($ba, ['booking_date' => '2027-04-01']);
    $tx2     = svcBankTx($ba, ['booking_date' => '2027-04-02']);

    $svc = new PostingRuleService;
    $e1  = $svc->post($tx1, $rule);
    $e2  = $svc->post($tx2, $rule);

    expect($e1->number)->toBe(1)
        ->and($e2->number)->toBe(2)
        ->and($e1->series)->toBe('B')
        ->and($e2->series)->toBe('B');
});

test('post requires_receipt is set when rule has it', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1921');
    $counter = svcAccount('3044', 'income');
    $rule    = svcRule($ba, $counter, ['requires_receipt' => true]);
    $tx      = svcBankTx($ba, ['booking_date' => '2027-04-01']);

    $entry = (new PostingRuleService)->post($tx, $rule);

    expect($entry->requires_receipt)->toBeTrue();
});

test('post throws when no open fiscal year covers transaction date', function () {
    $ba      = svcBankAccount('1922');
    $counter = svcAccount('3045', 'income');
    $rule    = svcRule($ba, $counter);
    $tx      = svcBankTx($ba, ['booking_date' => '2025-01-15']); // No FY for 2025

    expect(fn () => (new PostingRuleService)->post($tx, $rule))
        ->toThrow(\RuntimeException::class);
});

test('post tags vendor_id on transaction when rule has vendor', function () {
    $fy      = svcFiscalYear();
    $ba      = svcBankAccount('1923');
    $counter = svcAccount('3046', 'income');
    $vendor  = \App\Models\Accounting\Vendor::create(['code' => 'V100', 'name' => 'Test Leverantör']);
    $rule    = svcRule($ba, $counter, ['vendor_id' => $vendor->id]);
    $tx      = svcBankTx($ba, ['booking_date' => '2027-04-01']);

    (new PostingRuleService)->post($tx, $rule);

    expect($tx->fresh()->vendor_id)->toBe($vendor->id);
});

// ── postFromInboxEmail() ──────────────────────────────────────────────────────

test('postFromInboxEmail creates balanced 2-line entry', function () {
    $fy     = svcFiscalYear();
    $debit  = svcAccount('4000', 'expense');
    $credit = svcAccount('2440', 'liability');

    $email = AccountingInboxEmail::create([
        'from_email'  => 'faktura@vendor.com',
        'subject'     => 'Faktura mars 2027',
        'received_at' => now(),
        'status'      => 'unprocessed',
    ]);

    $rule = PostingRule::create([
        'name'              => 'Inkorgsregel',
        'trigger'           => PostingRule::TRIGGER_INBOX_EMAIL,
        'debit_account_id'  => $debit->id,
        'credit_account_id' => $credit->id,
        'is_active'         => true,
        'sort_order'        => 0,
        'series'            => 'A',
        'requires_receipt'  => false,
    ]);

    $entry = (new PostingRuleService)->postFromInboxEmail($email, $rule, 500_00, '2027-03-01');

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->currentLines()->count())->toBe(2);
});

test('postFromInboxEmail description includes rule name and email subject', function () {
    $fy     = svcFiscalYear();
    $debit  = svcAccount('4001', 'expense');
    $credit = svcAccount('2441', 'liability');

    $email = AccountingInboxEmail::create([
        'from_email'  => 'faktura@vendor.com',
        'subject'     => 'Spotify månadsavgift',
        'received_at' => now(),
        'status'      => 'unprocessed',
    ]);

    $rule = PostingRule::create([
        'name'              => 'Spotify kostnad',
        'trigger'           => PostingRule::TRIGGER_INBOX_EMAIL,
        'debit_account_id'  => $debit->id,
        'credit_account_id' => $credit->id,
        'is_active'         => true,
        'sort_order'        => 0,
        'series'            => 'A',
        'requires_receipt'  => false,
    ]);

    $entry = (new PostingRuleService)->postFromInboxEmail($email, $rule, 9900, '2027-03-01');

    expect($entry->description)->toContain('Spotify kostnad')
        ->and($entry->description)->toContain('Spotify månadsavgift');
});

test('postFromInboxEmail throws when no open fiscal year', function () {
    $debit  = svcAccount('4002', 'expense');
    $credit = svcAccount('2442', 'liability');

    $email = AccountingInboxEmail::create([
        'from_email'  => 'faktura@vendor.com',
        'subject'     => 'Faktura',
        'received_at' => now(),
        'status'      => 'unprocessed',
    ]);

    $rule = PostingRule::create([
        'name'              => 'Test',
        'trigger'           => PostingRule::TRIGGER_INBOX_EMAIL,
        'debit_account_id'  => $debit->id,
        'credit_account_id' => $credit->id,
        'is_active'         => true,
        'sort_order'        => 0,
        'series'            => 'A',
        'requires_receipt'  => false,
    ]);

    expect(fn () => (new PostingRuleService)->postFromInboxEmail($email, $rule, 500_00, '2027-03-01'))
        ->toThrow(\RuntimeException::class);
});
