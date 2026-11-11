<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function revAccount(string $code, string $type = Account::TYPE_ASSET): Account
{
    return Account::firstOrCreate(['code' => $code], [
        'name' => "Konto {$code}", 'type' => $type, 'is_active' => true, 'is_group' => false,
    ]);
}

function revFiscalYear(string $start = '2027-01-01', string $end = '2027-12-31', bool $locked = false): FiscalYear
{
    static $n = 0;
    return FiscalYear::create([
        'name' => 'FY ' . (++$n), 'starts_at' => $start, 'ends_at' => $end, 'is_locked' => $locked,
    ]);
}

function revEntry(FiscalYear $fy, array $lines, array $attrs = []): JournalEntry
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

// ── JournalEntry::reverse() ─────────────────────────────────────────────────

test('reverse() creates a linked entry with debit/credit swapped', function () {
    $fy   = revFiscalYear();
    $bank = revAccount('1930');
    $exp  = revAccount('6100', Account::TYPE_EXPENSE);

    $user  = User::factory()->create();
    $entry = revEntry($fy, [
        ['account_id' => $exp->id,  'debit' => 500_00, 'credit' => 0,      'description' => 'Inköp'],
        ['account_id' => $bank->id, 'debit' => 0,       'credit' => 500_00, 'description' => null],
    ]);

    $reversal = $entry->reverse($user->id, 'Felaktigt konto', Carbon::parse('2027-07-01'));

    expect($reversal->reverses_journal_entry_id)->toBe($entry->id)
        ->and($reversal->series)->toBe($entry->series)
        ->and($reversal->fiscal_year_id)->toBe($fy->id)
        ->and($reversal->description)->toContain('Vändning av')
        ->and($reversal->description)->toContain('Felaktigt konto');

    $lines = $reversal->currentLines;
    expect($lines)->toHaveCount(2);

    $expLine  = $lines->firstWhere('account_id', $exp->id);
    $bankLine = $lines->firstWhere('account_id', $bank->id);
    expect($expLine->credit)->toBe(500_00)->and($expLine->debit)->toBe(0)
        ->and($bankLine->debit)->toBe(500_00)->and($bankLine->credit)->toBe(0);

    // Original entry is completely untouched.
    $entry->refresh();
    expect($entry->currentLines)->toHaveCount(2)
        ->and($entry->hasBeenEdited())->toBeFalse();
});

test('reverse() links the original to the reversal both ways', function () {
    $fy    = revFiscalYear();
    $acc   = revAccount('1930');
    $acc2  = revAccount('6100', Account::TYPE_EXPENSE);
    $user  = User::factory()->create();
    $entry = revEntry($fy, [
        ['account_id' => $acc2->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $acc->id,  'debit' => 0,       'credit' => 100_00],
    ]);

    $reversal = $entry->reverse($user->id, 'Test', Carbon::parse('2027-07-01'));

    expect($entry->fresh()->reversedBy->id)->toBe($reversal->id)
        ->and($reversal->fresh()->reverses->id)->toBe($entry->id);
});

test('reverse() refuses to reverse an already-reversed entry', function () {
    $fy    = revFiscalYear();
    $acc   = revAccount('1930');
    $acc2  = revAccount('6100', Account::TYPE_EXPENSE);
    $user  = User::factory()->create();
    $entry = revEntry($fy, [
        ['account_id' => $acc2->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $acc->id,  'debit' => 0,       'credit' => 100_00],
    ]);

    $entry->reverse($user->id, 'Först', Carbon::parse('2027-07-01'));

    expect(fn () => $entry->reverse($user->id, 'Igen', Carbon::parse('2027-07-02')))->toThrow(RuntimeException::class);
});

test('reverse() throws when no open fiscal year covers the date', function () {
    $fy    = revFiscalYear(locked: true);
    $acc   = revAccount('1930');
    $acc2  = revAccount('6100', Account::TYPE_EXPENSE);
    $user  = User::factory()->create();
    $entry = revEntry($fy, [
        ['account_id' => $acc2->id, 'debit' => 100_00, 'credit' => 0],
        ['account_id' => $acc->id,  'debit' => 0,       'credit' => 100_00],
    ]);

    // No open fiscal year at all covers "today" in this test's date range.
    expect(fn () => $entry->reverse($user->id, 'Test'))->toThrow(RuntimeException::class);
});

// ── Livewire component ───────────────────────────────────────────────────────

test('reversal button flow works end to end via the component', function () {
    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'accounting-test', 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.manage']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $fy   = revFiscalYear(start: now()->startOfYear()->toDateString(), end: now()->endOfYear()->toDateString());
    $acc  = revAccount('1930');
    $acc2 = revAccount('6100', Account::TYPE_EXPENSE);
    $entry = revEntry($fy, [
        ['account_id' => $acc2->id, 'debit' => 250_00, 'credit' => 0],
        ['account_id' => $acc->id,  'debit' => 0,       'credit' => 250_00],
    ]);

    $this->actingAs($user);

    \Livewire\Volt\Volt::test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->call('startReversing')
        ->assertSet('reversing', true)
        ->set('reverseNote', 'Bokad på fel period')
        ->call('confirmReverse');

    $reversal = JournalEntry::where('reverses_journal_entry_id', $entry->id)->firstOrFail();
    expect($reversal->currentLines->firstWhere('account_id', $acc->id)->debit)->toBe(250_00);
});
