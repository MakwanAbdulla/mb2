<?php

use App\Models\Accounting\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Redirect scratch/attachment writes away from the real storage disk — see
    // InvoiceZipImporterTest.php for why this matters (CLI-owned files break
    // www-data's access on the next real web request).
    Storage::fake('local');
});

function importZipManager(): User
{
    Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'accounting-manager-smoke', 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.manage']);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('renders the upload step', function () {
    Volt::actingAs(importZipManager())
        ->test('admin.accounting.invoices.payable.import-zip')
        ->assertOk()
        ->assertSee('Importera leverantörsfakturor från ZIP');
});

it('moves to the review step and creates a draft invoice on confirm', function () {
    $account = \App\Models\Accounting\Account::create([
        'code' => '6110', 'name' => 'Kontorsmaterial', 'type' => 'expense', 'is_active' => true, 'is_group' => false,
    ]);
    Vendor::create(['code' => 'ACME', 'name' => 'Acme AB', 'type' => 'external', 'org_number' => '556677-8899', 'default_account_id' => $account->id]);

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<p>Acme AB<br>Org.nr 556677-8899<br>Fakturadatum: 2027-03-01<br>Att betala: 200,00 kr</p>')->output();

    $zipPath = tempnam(sys_get_temp_dir(), 'ziptest') . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('invoice.pdf', $pdf);
    $zip->close();

    $file = new \Illuminate\Http\Testing\File('invoices.zip', fopen($zipPath, 'r'));

    $component = Volt::actingAs(importZipManager())
        ->test('admin.accounting.invoices.payable.import-zip')
        ->set('zipFile', $file)
        ->assertSet('reviewing', true)
        ->assertSee('Acme AB');

    $component->call('confirmImport')
        ->assertSet('imported', true)
        ->assertSet('importedCount', 1);

    expect(\App\Models\Accounting\Invoice::count())->toBe(1)
        ->and(\App\Models\Accounting\AccountingDocument::count())->toBe(1);
});
