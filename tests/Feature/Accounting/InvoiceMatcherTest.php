<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalSeries;
use App\Models\Accounting\Vendor;
use App\Services\Accounting\InvoiceMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);

    // linkAndPay() now books a real clearing journal entry (2440/1510 against the
    // bank account) instead of just flipping statuses — needs an open fiscal
    // year, the payment journal series, and the clearing accounts to exist.
    FiscalYear::firstOrCreate(
        ['name' => '2027'],
        ['starts_at' => '2027-01-01', 'ends_at' => '2027-12-31', 'is_locked' => false]
    );

    JournalSeries::firstOrCreate(
        ['code' => 'E'],
        ['name' => 'Utbetalningar till leverantörer', 'default_for' => 'ap_payment', 'is_manual' => false, 'is_lockable' => true, 'is_system' => true, 'sort_order' => 5]
    );
    JournalSeries::firstOrCreate(
        ['code' => 'C'],
        ['name' => 'Inbetalningar från kunder', 'default_for' => 'ar_payment', 'is_manual' => false, 'is_lockable' => true, 'is_system' => true, 'sort_order' => 3]
    );

    Account::firstOrCreate(
        ['code' => '2440'],
        ['name' => 'Leverantörsskulder', 'type' => Account::TYPE_LIABILITY, 'is_active' => true, 'is_group' => false]
    );
    Account::firstOrCreate(
        ['code' => '1510'],
        ['name' => 'Kundfordringar', 'type' => Account::TYPE_ASSET, 'is_active' => true, 'is_group' => false]
    );
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function imBankAccount(): BankAccount
{
    $ledger = \App\Models\Accounting\Account::create([
        'code'      => '191' . rand(1, 9),
        'name'      => 'Bank',
        'type'      => 'asset',
        'is_active' => true,
        'is_group'  => false,
    ]);
    return BankAccount::create(['name' => 'Test Bank', 'account_id' => $ledger->id]);
}

function imBankTx(array $attrs = []): BankTransaction
{
    $ba = imBankAccount();
    return BankTransaction::create(array_merge([
        'bank_account_id'  => $ba->id,
        'amount'           => 100_00,
        'booking_date'     => '2027-06-15',
        'transaction_date' => '2027-06-15',
        'description'      => 'Test',
        'reference'        => null,
        'status'           => BankTransaction::STATUS_UNMATCHED,
    ], $attrs));
}

function imVendor(): Vendor
{
    static $n = 0;
    return Vendor::create(['code' => 'V' . (++$n), 'name' => 'Vendor ' . $n]);
}

function imPayableInvoice(Vendor $vendor, int $total, array $attrs = []): Invoice
{
    static $n = 0;
    return Invoice::create(array_merge([
        'type'           => Invoice::TYPE_PAYABLE,
        'invoice_number' => 'INV-' . (++$n),
        'invoice_date'   => '2027-06-01',
        'vendor_id'      => $vendor->id,
        'amount_ex_vat'  => $total,
        'total'          => $total,
        'status'         => Invoice::STATUS_POSTED,
        'due_date'       => '2027-06-15',
    ], $attrs));
}

function imReceivableInvoice(int $total, array $attrs = []): Invoice
{
    static $n = 0;
    return Invoice::create(array_merge([
        'type'           => Invoice::TYPE_RECEIVABLE,
        'invoice_number' => 'REC-' . (++$n),
        'invoice_date'   => '2027-06-01',
        'amount_ex_vat'  => $total,
        'total'          => $total,
        'status'         => Invoice::STATUS_POSTED,
        'due_date'       => '2027-06-15',
    ], $attrs));
}

// ── Payable matching ──────────────────────────────────────────────────────────

test('payable: matches by vendor + amount within tolerance', function () {
    Mail::fake();

    $vendor  = imVendor();
    $invoice = imPayableInvoice($vendor, 500_00, ['due_date' => '2027-06-15']);
    $tx      = imBankTx(['amount' => -500_00, 'booking_date' => '2027-06-15', 'vendor_id' => $vendor->id]);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(1);
    expect($tx->fresh()->status)->toBe(BankTransaction::STATUS_MATCHED);
    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID);
});

test('payable: books a clearing journal entry (2440 debit, bank credit)', function () {
    Mail::fake();

    $vendor  = imVendor();
    $invoice = imPayableInvoice($vendor, 500_00, ['due_date' => '2027-06-15']);
    $tx      = imBankTx(['amount' => -500_00, 'booking_date' => '2027-06-15', 'vendor_id' => $vendor->id]);

    (new InvoiceMatcherService)->processImport(collect([$tx]));

    $tx->refresh();
    $invoice->refresh();

    expect($tx->journal_entry_id)->not->toBeNull()
        ->and($invoice->payment_journal_entry_id)->toBe($tx->journal_entry_id);

    $entry = $tx->journalEntry;
    expect($entry->currentLines)->toHaveCount(2);

    $apLine = $entry->currentLines->firstWhere('account.code', '2440');
    expect($apLine->debit)->toBe(500_00)->and($apLine->credit)->toBe(0);

    $bankLine = $entry->currentLines->firstWhere('account.code', '!=', '2440');
    expect($bankLine->credit)->toBe(500_00)->and($bankLine->debit)->toBe(0);
});

test('payable: rejects when amount does not match', function () {
    Mail::fake();

    $vendor  = imVendor();
    imPayableInvoice($vendor, 500_00);
    $tx = imBankTx(['amount' => -499_00, 'vendor_id' => $vendor->id]);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(0);
});

test('payable: rejects when vendor does not match', function () {
    Mail::fake();

    $v1 = imVendor();
    $v2 = imVendor();
    imPayableInvoice($v1, 500_00);
    $tx = imBankTx(['amount' => -500_00, 'vendor_id' => $v2->id]);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(0);
});

test('payable: rejects when date exceeds 14-day tolerance', function () {
    Mail::fake();

    $vendor  = imVendor();
    imPayableInvoice($vendor, 500_00, ['due_date' => '2027-06-01']);
    $tx = imBankTx(['amount' => -500_00, 'booking_date' => '2027-06-16', 'vendor_id' => $vendor->id]);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(0);
});

test('payable: accepts dates within 14-day tolerance', function () {
    Mail::fake();

    $vendor  = imVendor();
    imPayableInvoice($vendor, 500_00, ['due_date' => '2027-06-01']);
    $tx = imBankTx(['amount' => -500_00, 'booking_date' => '2027-06-15', 'vendor_id' => $vendor->id]); // 14 days

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(1);
});

test('payable: transaction without vendor_id is not matched', function () {
    Mail::fake();

    $vendor  = imVendor();
    imPayableInvoice($vendor, 500_00);
    $tx = imBankTx(['amount' => -500_00, 'vendor_id' => null]);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(0);
});

// ── Receivable matching ───────────────────────────────────────────────────────

test('receivable: OCR match is definitive and marks invoice paid', function () {
    Mail::fake();

    $invoice = imReceivableInvoice(300_00, ['ocr_number' => '1234567890']);
    $tx      = imBankTx(['amount' => 300_00, 'reference' => '1234567890']);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(1)
        ->and($result['potential'])->toBeEmpty();
    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID);
});

test('receivable: books a clearing journal entry (bank debit, 1510 credit)', function () {
    Mail::fake();

    $invoice = imReceivableInvoice(300_00, ['ocr_number' => '1234567890']);
    $tx      = imBankTx(['amount' => 300_00, 'reference' => '1234567890']);

    (new InvoiceMatcherService)->processImport(collect([$tx]));

    $tx->refresh();
    $invoice->refresh();

    expect($tx->journal_entry_id)->not->toBeNull()
        ->and($invoice->payment_journal_entry_id)->toBe($tx->journal_entry_id);

    $arLine = $tx->journalEntry->currentLines->firstWhere('account.code', '1510');
    expect($arLine->credit)->toBe(300_00)->and($arLine->debit)->toBe(0);
});

test('receivable: OCR match strips non-digits for comparison', function () {
    Mail::fake();

    $invoice = imReceivableInvoice(300_00, ['ocr_number' => '12345 6']);
    $tx      = imBankTx(['amount' => 300_00, 'reference' => 'OCR 123456']);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(1);
});

test('receivable: amount-only match within 30 days is returned as potential', function () {
    Mail::fake();

    $invoice = imReceivableInvoice(400_00, ['ocr_number' => null, 'due_date' => '2027-06-15']);
    $tx      = imBankTx(['amount' => 400_00, 'reference' => null, 'booking_date' => '2027-06-15']);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(0)
        ->and($result['potential'])->toHaveCount(1)
        ->and($result['potential'][0]['invoices']->first()->id)->toBe($invoice->id);
});

test('receivable: amount-only match outside 30-day window is not a potential', function () {
    Mail::fake();

    imReceivableInvoice(400_00, ['ocr_number' => null, 'due_date' => '2027-01-01']);
    $tx = imBankTx(['amount' => 400_00, 'reference' => null, 'booking_date' => '2027-06-15']); // 165 days

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(0)
        ->and($result['potential'])->toBeEmpty();
});

// ── Corporate card skipped ────────────────────────────────────────────────────

test('corporate card transaction is skipped entirely', function () {
    Mail::fake();

    $vendor  = imVendor();
    imPayableInvoice($vendor, 200_00);

    $member = \App\Models\Membership\Member::factory()->create();
    $ba     = imBankAccount();
    $card   = \App\Models\Accounting\CorporateCard::create([
        'member_id'      => $member->id,
        'card_reference' => 'CARD-TEST-001',
        'is_active'      => true,
    ]);

    $tx = BankTransaction::create([
        'bank_account_id'  => $ba->id,
        'amount'           => -200_00,
        'booking_date'     => '2027-06-15',
        'transaction_date' => '2027-06-15',
        'description'      => 'Card purchase',
        'status'           => BankTransaction::STATUS_UNMATCHED,
        'vendor_id'        => $vendor->id,
        'corporate_card_id'=> $card->id,
    ]);

    $result = (new InvoiceMatcherService)->processImport(collect([$tx]));

    expect($result['matched'])->toBe(0);
    expect($tx->fresh()->status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

// ── confirmMatch ─────────────────────────────────────────────────────────────

test('confirmMatch links transaction and marks invoice paid', function () {
    Mail::fake();

    $vendor  = imVendor();
    $invoice = imPayableInvoice($vendor, 500_00);
    $tx      = imBankTx(['amount' => -500_00, 'vendor_id' => $vendor->id]);

    (new InvoiceMatcherService)->confirmMatch($tx, $invoice);

    expect($tx->fresh()->status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($tx->fresh()->invoice_id)->toBe($invoice->id)
        ->and($tx->fresh()->journal_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->fresh()->paid_at)->not->toBeNull()
        ->and($invoice->fresh()->payment_journal_entry_id)->toBe($tx->fresh()->journal_entry_id);
});

test('confirmMatch refuses to link a transaction that is already matched or posted', function () {
    Mail::fake();

    $vendor  = imVendor();
    $invoice = imPayableInvoice($vendor, 500_00);
    $tx      = imBankTx(['amount' => -500_00, 'vendor_id' => $vendor->id, 'status' => BankTransaction::STATUS_POSTED]);

    expect(fn () => (new InvoiceMatcherService)->confirmMatch($tx, $invoice))
        ->toThrow(RuntimeException::class);

    // Nothing changed — the pre-existing (e.g. wrong-account) booking is left alone
    // rather than silently overwritten, and the invoice is not marked paid.
    expect($tx->fresh()->status)->toBe(BankTransaction::STATUS_POSTED)
        ->and($tx->fresh()->invoice_id)->toBeNull()
        ->and($invoice->fresh()->status)->toBe(Invoice::STATUS_POSTED);
});

test('confirmMatch refuses to link a transaction that already has a journal entry', function () {
    Mail::fake();

    $vendor  = imVendor();
    $invoice = imPayableInvoice($vendor, 500_00);
    $tx      = imBankTx(['amount' => -500_00, 'vendor_id' => $vendor->id]);

    // Simulate a transaction that's technically "unmatched" but already has a
    // journal entry from some other path — should still be refused.
    $fiscalYear = FiscalYear::first();
    $otherEntry = \App\Models\Accounting\JournalEntry::create([
        'fiscal_year_id' => $fiscalYear->id,
        'series'         => 'A',
        'number'         => 1,
        'date'           => '2027-06-15',
        'description'    => 'Unrelated entry',
    ]);
    $tx->update(['journal_entry_id' => $otherEntry->id]);

    expect(fn () => (new InvoiceMatcherService)->confirmMatch($tx, $invoice))
        ->toThrow(RuntimeException::class);
});
