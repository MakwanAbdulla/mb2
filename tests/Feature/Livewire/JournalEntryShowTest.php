<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function jeShowAccount(string $code, string $type = Account::TYPE_ASSET): Account
{
    return Account::firstOrCreate(['code' => $code], [
        'name' => "Konto {$code}", 'type' => $type, 'is_active' => true, 'is_group' => false,
    ]);
}

function jeShowFiscalYear(): FiscalYear
{
    static $n = 0;
    return FiscalYear::create([
        'name' => 'FY ' . (++$n), 'starts_at' => '2027-01-01', 'ends_at' => '2027-12-31', 'is_locked' => false,
    ]);
}

function jeShowEntry(FiscalYear $fy, array $lines, array $attrs = []): JournalEntry
{
    static $n = 0;
    $entry = JournalEntry::create(array_merge([
        'fiscal_year_id' => $fy->id,
        'series'         => 'A',
        'number'         => ++$n,
        'date'           => '2027-06-15',
        'description'    => 'Test verifikation',
    ], $attrs));

    foreach ($lines as $line) {
        $entry->lines()->create($line);
    }

    return $entry;
}

function jeShowManager(): User
{
    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'accounting-manager-test', 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.manage']);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── Edit flow ─────────────────────────────────────────────────────────────────

test('startEditing populates the edit form from current lines', function () {
    $this->actingAs(jeShowManager());

    $fy   = jeShowFiscalYear();
    $bank = jeShowAccount('1930');
    $exp  = jeShowAccount('6100', Account::TYPE_EXPENSE);
    $entry = jeShowEntry($fy, [
        ['account_id' => $exp->id,  'debit' => 500_00, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0,       'credit' => 500_00],
    ]);

    Volt::test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->call('startEditing')
        ->assertSet('editing', true)
        ->assertSet('editLines.0.account_id', $exp->id)
        ->assertSet('editLines.1.account_id', $bank->id);
});

test('the edit button is rendered and reachable for an unlocked entry', function () {
    $this->actingAs(jeShowManager());

    $fy   = jeShowFiscalYear();
    $bank = jeShowAccount('1930');
    $exp  = jeShowAccount('6100', Account::TYPE_EXPENSE);
    $entry = jeShowEntry($fy, [
        ['account_id' => $exp->id,  'debit' => 500_00, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0,       'credit' => 500_00],
    ]);

    Volt::test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->assertSee('Redigera rader')
        ->call('startEditing')
        ->assertDontSee('Redigera rader');
});

test('the edit button is hidden for a locked entry', function () {
    $this->actingAs(jeShowManager());

    $fy   = jeShowFiscalYear();
    $bank = jeShowAccount('1930');
    $exp  = jeShowAccount('6100', Account::TYPE_EXPENSE);
    $entry = jeShowEntry($fy, [
        ['account_id' => $exp->id,  'debit' => 500_00, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0,       'credit' => 500_00],
    ], ['is_locked' => true]);

    Volt::test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->assertDontSee('Redigera rader');
});

test('saveEdit supersedes old lines and creates new current lines', function () {
    $this->actingAs(jeShowManager());

    $fy   = jeShowFiscalYear();
    $bank = jeShowAccount('1930');
    $exp  = jeShowAccount('6100', Account::TYPE_EXPENSE);
    $exp2 = jeShowAccount('6200', Account::TYPE_EXPENSE);
    $entry = jeShowEntry($fy, [
        ['account_id' => $exp->id,  'debit' => 500_00, 'credit' => 0],
        ['account_id' => $bank->id, 'debit' => 0,       'credit' => 500_00],
    ]);

    Volt::test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->call('startEditing')
        ->set('editLines.0.account_id', $exp2->id)
        ->set('editNote', 'Bokade på fel konto')
        ->call('saveEdit')
        ->assertSet('editing', false);

    $entry->refresh();
    expect($entry->currentLines)->toHaveCount(2)
        ->and($entry->currentLines->firstWhere('account_id', $exp2->id))->not->toBeNull()
        ->and($entry->supersededLines()->count())->toBe(2)
        ->and($entry->hasBeenEdited())->toBeTrue()
        ->and($entry->edit_note)->toBe('Bokade på fel konto');
});
