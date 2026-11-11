<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\Customer;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('accounting.view');
    $this->createPermission('accounting.manage');
});

function accountingAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('accounting.manage');
    return $user;
}

function makeAccWriteFiscalYear(): FiscalYear
{
    return FiscalYear::create([
        'name'      => '2027',
        'starts_at' => '2027-01-01',
        'ends_at'   => '2027-12-31',
        'is_locked' => false,
    ]);
}

function makeAccWriteAccount(string $type = 'expense', string $code = '6000'): Account
{
    static $seq = 0;
    return Account::create([
        'code'      => $code . (++$seq),
        'name'      => "Konto {$code}",
        'type'      => $type,
        'is_group'  => false,
        'is_active' => true,
    ]);
}

// ── Vendors ───────────────────────────────────────────────────────────────────

test('admin can create vendor', function () {
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson('/api/v1/accounting/vendors', [
        'name'       => 'Acme AB',
        'org_number' => '556000-0000',
        'email'      => 'faktura@acme.se',
        'type'       => 'external',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Acme AB');
});

test('create vendor requires accounting.manage permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/accounting/vendors', ['name' => 'Test', 'type' => 'external'])->assertForbidden();
});

test('create vendor requires auth', function () {
    $this->postJson('/api/v1/accounting/vendors', ['name' => 'Test', 'type' => 'external'])->assertUnauthorized();
});

test('admin can update vendor', function () {
    $vendor = Vendor::create(['name' => 'Gammalt namn', 'type' => 'external', 'is_active' => true, 'code' => 'V00001']);
    Sanctum::actingAs(accountingAdminUser());

    $this->patchJson("/api/v1/accounting/vendors/{$vendor->id}", [
        'name' => 'Nytt namn',
    ])->assertOk()->assertJsonPath('data.name', 'Nytt namn');
});

// ── Customers ─────────────────────────────────────────────────────────────────

test('admin can create customer', function () {
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson('/api/v1/accounting/customers', [
        'name'  => 'Föreningen XYZ',
        'email' => 'xyz@example.com',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Föreningen XYZ');
});

test('admin can update customer', function () {
    $customer = Customer::create(['name' => 'Gammal kund', 'is_active' => true]);
    Sanctum::actingAs(accountingAdminUser());

    $this->patchJson("/api/v1/accounting/customers/{$customer->id}", [
        'name' => 'Ny kund',
    ])->assertOk()->assertJsonPath('data.name', 'Ny kund');
});

// ── Journal entries ───────────────────────────────────────────────────────────

test('admin can create balanced journal entry', function () {
    $fy      = makeAccWriteFiscalYear();
    $debit   = makeAccWriteAccount('expense', '6000');
    $credit  = makeAccWriteAccount('asset',   '1900');
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson('/api/v1/accounting/journal-entries', [
        'fiscal_year_id' => $fy->id,
        'date'           => '2027-03-15',
        'series'         => 'A',
        'description'    => 'Inköp kontorsmaterial',
        'lines'          => [
            ['account_id' => $debit->id,  'debit' => 50000, 'credit' => 0],
            ['account_id' => $credit->id, 'debit' => 0,     'credit' => 50000],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.description', 'Inköp kontorsmaterial')
        ->assertJsonCount(2, 'data.lines');
});

test('unbalanced journal entry is rejected', function () {
    $fy     = makeAccWriteFiscalYear();
    $acc1   = makeAccWriteAccount('expense', '6000');
    $acc2   = makeAccWriteAccount('asset', '1900');
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson('/api/v1/accounting/journal-entries', [
        'fiscal_year_id' => $fy->id,
        'date'           => '2027-03-15',
        'series'         => 'A',
        'description'    => 'Obalanserat',
        'lines'          => [
            ['account_id' => $acc1->id, 'debit' => 50000, 'credit' => 0],
            ['account_id' => $acc2->id, 'debit' => 0,     'credit' => 40000],
        ],
    ])->assertStatus(422);
});

function makeInvoice(string $status, ?int $createdBy = null): Invoice
{
    return Invoice::create([
        'type'         => Invoice::TYPE_PAYABLE,
        'invoice_date' => '2027-01-15',
        'amount_ex_vat' => 10000,
        'total'        => 12500,
        'status'       => $status,
        'created_by'   => $createdBy,
    ]);
}

// ── Invoices ──────────────────────────────────────────────────────────────────

test('admin can mark approved invoice as paid', function () {
    $invoice = makeInvoice(Invoice::STATUS_APPROVED);
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/mark-paid", [
        'payment_method' => 'bank_transfer',
    ])->assertOk()->assertJsonPath('status', Invoice::STATUS_PAID);
});

test('cannot mark draft invoice as paid', function () {
    $invoice = makeInvoice(Invoice::STATUS_DRAFT);
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/mark-paid", [
        'payment_method' => 'bank_transfer',
    ])->assertStatus(422);
});

test('admin can cancel an approved invoice', function () {
    $invoice = makeInvoice(Invoice::STATUS_APPROVED);
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', Invoice::STATUS_CANCELLED);
});

test('cannot cancel a paid invoice', function () {
    $invoice = makeInvoice(Invoice::STATUS_PAID);
    Sanctum::actingAs(accountingAdminUser());

    $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/cancel")
        ->assertStatus(422);
});

test('a user with accounting.approve can approve an invoice submitted by someone else', function () {
    $this->createPermission('accounting.approve');
    $submitter = User::factory()->create();
    $invoice   = makeInvoice(Invoice::STATUS_PENDING_APPROVAL, $submitter->id);

    $approver = User::factory()->create();
    $approver->givePermissionTo('accounting.approve');
    Sanctum::actingAs($approver);

    $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/approve")
        ->assertOk()
        ->assertJsonPath('status', Invoice::STATUS_APPROVED);
});

test('a user cannot approve an invoice they submitted themselves', function () {
    $this->createPermission('accounting.approve');
    $submitter = User::factory()->create();
    $submitter->givePermissionTo('accounting.approve');
    $invoice   = makeInvoice(Invoice::STATUS_PENDING_APPROVAL, $submitter->id);

    Sanctum::actingAs($submitter);

    $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/approve")
        ->assertForbidden();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PENDING_APPROVAL);
});

// ── ZIP import ────────────────────────────────────────────────────────────────

function accWriteBuildZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'ziptest') . '.zip';
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $path;
}

function accWriteFakeInvoicePdf(array $lines): string
{
    $html = '<p>' . implode('<br>', array_map('e', $lines)) . '</p>';

    return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
}

test('import-zip creates a draft invoice when the vendor matches', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    $account = makeAccWriteAccount();
    Vendor::create(['code' => 'ACME', 'name' => 'Acme AB', 'type' => 'external', 'org_number' => '556677-8899', 'default_account_id' => $account->id]);

    Sanctum::actingAs(accountingAdminUser());

    $zipPath = accWriteBuildZip(['invoice.pdf' => accWriteFakeInvoicePdf(['Acme AB', 'Org.nr 556677-8899', 'Fakturadatum: 2027-03-01', 'Att betala: 200,00 kr'])]);
    $file    = new \Illuminate\Http\UploadedFile($zipPath, 'invoices.zip', 'application/zip', null, true);

    $this->post('/api/v1/accounting/invoices/import-zip', ['file' => $file])
        ->assertOk()
        ->assertJsonCount(1, 'data.created')
        ->assertJsonCount(0, 'data.skipped');

    expect(Invoice::count())->toBe(1)
        ->and(Invoice::first()->status)->toBe(Invoice::STATUS_DRAFT);
});

test('import-zip skips files whose vendor does not match the register', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    Sanctum::actingAs(accountingAdminUser());

    $zipPath = accWriteBuildZip(['invoice.pdf' => accWriteFakeInvoicePdf(['Okänd Firma', 'Org.nr 999999-9999', 'Att betala: 200,00 kr'])]);
    $file    = new \Illuminate\Http\UploadedFile($zipPath, 'invoices.zip', 'application/zip', null, true);

    $this->post('/api/v1/accounting/invoices/import-zip', ['file' => $file])
        ->assertOk()
        ->assertJsonCount(0, 'data.created')
        ->assertJsonCount(1, 'data.skipped');

    expect(Invoice::count())->toBe(0);
});

test('import-zip requires accounting.manage permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('accounting.view');
    Sanctum::actingAs($user);

    $zipPath = accWriteBuildZip(['invoice.pdf' => "%PDF-1.4\n%%EOF"]);
    $file    = new \Illuminate\Http\UploadedFile($zipPath, 'invoices.zip', 'application/zip', null, true);

    $this->post('/api/v1/accounting/invoices/import-zip', ['file' => $file])
        ->assertForbidden();
});

test('mark-paid requires accounting.manage permission', function () {
    $invoice = makeInvoice(Invoice::STATUS_APPROVED);
    $user    = User::factory()->create();
    $user->givePermissionTo('accounting.view');
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/accounting/invoices/{$invoice->id}/mark-paid", [
        'payment_method' => 'bank_transfer',
    ])->assertForbidden();
});
