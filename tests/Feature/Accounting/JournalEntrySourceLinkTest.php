<?php

use App\Models\Accounting\Customer;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Regression for #248: a journal entry whose source is an invoice linked to
// a route that doesn't exist, so the page gave 500.

function sourceLinkEntry(array $invoiceAttrs): array
{
    foreach (['accounting.view', 'accounting.manage', 'users.manage-roles'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $role = Role::firstOrCreate(['name' => 'accounting-test', 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.manage']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $fy = FiscalYear::create([
        'name' => 'FY', 'starts_at' => now()->startOfYear(), 'ends_at' => now()->endOfYear(), 'is_locked' => false,
    ]);

    $invoice = Invoice::create(array_merge([
        'invoice_date' => now()->toDateString(),
        'amount_ex_vat' => 1000_00,
        'total' => 1000_00,
        'status' => Invoice::STATUS_POSTED,
    ], $invoiceAttrs));

    $entry = JournalEntry::create([
        'fiscal_year_id' => $fy->id,
        'series' => 'B',
        'number' => 1,
        'date' => now()->toDateString(),
        'description' => 'Faktura',
        'source_type' => Invoice::class,
        'source_id' => $invoice->id,
    ]);

    return [$user, $invoice, $entry];
}

test('a journal entry from a kundfaktura links to the kundfaktura', function () {
    [$user, $invoice, $entry] = sourceLinkEntry([
        'type' => Invoice::TYPE_RECEIVABLE,
        'customer_id' => Customer::create(['name' => 'Testkund AB'])->id,
        'invoice_number' => '2026-AR-0001',
    ]);

    $this->actingAs($user);

    Volt::test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->assertSee(route('admin.accounting.invoices.receivable.show', $invoice))
        ->assertSee('Kundfaktura 2026-AR-0001');
});

test('a journal entry from a leverantörsfaktura links to the leverantörsfaktura', function () {
    [$user, $invoice, $entry] = sourceLinkEntry([
        'type' => Invoice::TYPE_PAYABLE,
        'vendor_id' => Vendor::create(['code' => 'L001', 'name' => 'Testleverantör AB'])->id,
    ]);

    $this->actingAs($user);

    Volt::test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->assertSee(route('admin.accounting.invoices.payable.show', $invoice))
        ->assertSee('Leverantörsfaktura #'.$invoice->id);
});
