<?php

use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function vendorReportViewer(): User
{
    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'accounting-viewer-smoke', 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view']);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('renders the vendor report empty state without a fiscal year selection', function () {
    Volt::actingAs(vendorReportViewer())
        ->test('admin.accounting.reports.vendor-report')
        ->assertOk()
        ->assertSee('Välj ett räkenskapsår för att visa leverantörsrapport.');
});

it('aggregates payable invoices per vendor for the selected fiscal year', function () {
    $fy = FiscalYear::create([
        'name' => 'FY 2026', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31', 'is_locked' => false,
    ]);

    $vendor = Vendor::create([
        'code' => 'LEV1', 'name' => 'Testleverantör AB', 'type' => Vendor::TYPE_EXTERNAL,
    ]);

    Invoice::create([
        'type' => Invoice::TYPE_PAYABLE, 'vendor_id' => $vendor->id,
        'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'amount_ex_vat' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'status' => Invoice::STATUS_PAID,
    ]);

    Invoice::create([
        'type' => Invoice::TYPE_PAYABLE, 'vendor_id' => $vendor->id,
        'invoice_date' => '2026-04-01', 'due_date' => '2020-01-01',
        'amount_ex_vat' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'status' => Invoice::STATUS_APPROVED,
    ]);

    // Cancelled invoices are excluded from the report entirely.
    Invoice::create([
        'type' => Invoice::TYPE_PAYABLE, 'vendor_id' => $vendor->id,
        'invoice_date' => '2026-05-01', 'due_date' => '2026-05-31',
        'amount_ex_vat' => 9999, 'vat_amount' => 0, 'total' => 9999,
        'status' => Invoice::STATUS_CANCELLED,
    ]);

    Volt::actingAs(vendorReportViewer())
        ->test('admin.accounting.reports.vendor-report')
        ->set('fiscalYearId', $fy->id)
        ->assertOk()
        ->assertSee('Testleverantör AB')
        ->assertSee('150,00 kr') // total: 10 000 + 5 000 öre
        ->assertSee('100,00 kr') // paid: 10 000 öre
        ->assertSee('50,00 kr'); // unpaid: 5 000 öre

    $report = app(App\Services\Accounting\AccountingReportService::class)->vendorReport($fy->id);

    expect($report['totals'])->toBe([
        'invoice_count' => 2,
        'total_ore'     => 15000,
        'paid_ore'      => 10000,
        'unpaid_ore'    => 5000,
        'overdue_count' => 1,
    ]);
});
