<?php

/*
 * Regression for #110: Livewire 4 *moves* the temporary upload when storeAs()/store()
 * targets the same disk as livewire-tmp. Reading getMimeType()/getSize() afterwards
 * looked for the file at its old location and crashed, leaving an orphaned file.
 */

use App\Livewire\Admin\Meeting\MeetingShow;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\ExpenseClaim;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\MeetingDocument;
use App\Models\Membership\Member;
use App\Models\User;
use App\Support\StoredUpload;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Same fake disk for livewire-tmp and the target, like production — that is what triggers the move.
    Storage::fake('local');
});

function uploadUser(array $permissions = []): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function receiptPng(): UploadedFile
{
    return UploadedFile::fake()->image('kvitto.png', 40, 40);
}

function assertStoredWithMetadata(string $disk, string $path, ?string $mime, int $size, string $originalName): void
{
    Storage::disk($disk)->assertExists($path);
    expect($mime)->toBe('image/png')
        ->and($size)->toBeGreaterThan(0)
        ->and($originalName)->toBe('kvitto.png');
}

test('expense claim with receipt is saved with file metadata', function () {
    $user = uploadUser();
    Member::factory()->create(['user_id' => $user->id]);

    Volt::actingAs($user)->test('member.expense-claims.create')
        ->set('claimType', ExpenseClaim::CLAIM_TYPE_REIMBURSEMENT)
        ->set('description', 'Turneringspriser')
        ->set('purchasedAt', now()->toDateString())
        ->set('amount', '249.50')
        ->set('receipt', receiptPng())
        ->call('save')
        ->assertHasNoErrors();

    $claim = ExpenseClaim::sole();
    assertStoredWithMetadata('local', $claim->receipt_path, $claim->receipt_mime_type, $claim->receipt_size_bytes, $claim->receipt_original_name);
});

test('accounting document upload is saved with file metadata', function () {
    Volt::actingAs(uploadUser(['accounting.manage']))->test('admin.accounting.documents.index')
        ->set('uploadFile', receiptPng())
        ->set('uploadType', 'receipt')
        ->call('saveDocument')
        ->assertHasNoErrors();

    $doc = AccountingDocument::sole();
    assertStoredWithMetadata('local', $doc->path, $doc->mime_type, $doc->size_bytes, $doc->original_name);
});

test('receivable invoice document upload is saved with file metadata', function () {
    $invoice = Invoice::create([
        'type' => Invoice::TYPE_RECEIVABLE, 'invoice_date' => '2027-06-01', 'due_date' => '2027-06-30',
        'amount_ex_vat' => 100_00, 'vat_amount' => 0, 'total' => 100_00, 'status' => Invoice::STATUS_DRAFT,
    ]);

    Volt::actingAs(uploadUser(['accounting.manage']))
        ->test('admin.accounting.invoices.receivable.show', ['invoice' => $invoice])
        ->set('newDocument', receiptPng())
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $doc = AccountingDocument::sole();
    assertStoredWithMetadata('local', $doc->path, $doc->mime_type, $doc->size_bytes, $doc->original_name);
});

test('payable invoice document upload is saved with file metadata', function () {
    $invoice = Invoice::create([
        'type' => Invoice::TYPE_PAYABLE, 'invoice_date' => '2027-06-01', 'due_date' => '2027-06-30',
        'amount_ex_vat' => 100_00, 'vat_amount' => 0, 'total' => 100_00, 'status' => Invoice::STATUS_DRAFT,
    ]);

    // The page and its layout check many permissions, and Spatie throws on unknown names.
    $this->seed(PermissionSeeder::class);

    Volt::actingAs(uploadUser(['accounting.manage']))
        ->test('admin.accounting.invoices.payable.show', ['invoice' => $invoice])
        ->set('newDocument', receiptPng())
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $doc = AccountingDocument::sole();
    assertStoredWithMetadata('local', $doc->path, $doc->mime_type, $doc->size_bytes, $doc->original_name);
});

test('journal entry attachment upload is saved with file metadata', function () {
    $fy = FiscalYear::create(['name' => 'FY', 'starts_at' => '2027-01-01', 'ends_at' => '2027-12-31', 'is_locked' => false]);
    $entry = JournalEntry::create([
        'fiscal_year_id' => $fy->id, 'series' => 'A', 'number' => 1,
        'date' => '2027-06-15', 'description' => 'Test',
    ]);

    Volt::actingAs(uploadUser(['accounting.view', 'accounting.manage']))
        ->test('admin.accounting.journal-entries.show', ['journalEntry' => $entry])
        ->set('newAttachment', receiptPng())
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $doc = AccountingDocument::sole();
    assertStoredWithMetadata('local', $doc->path, $doc->mime_type, $doc->size_bytes, $doc->original_name);
});

test('meeting agenda file upload is saved with file metadata', function () {
    $user = uploadUser(['meetings.view', 'meetings.manage']);
    $meeting = Meeting::create([
        'title' => 'Styrelsemöte', 'meeting_type' => 'board', 'status' => 'planned',
        'scheduled_at' => now(), 'created_by' => $user->id,
    ]);
    $item = MeetingAgendaItem::create(['meeting_id' => $meeting->id, 'sort_order' => 1, 'title' => 'Ekonomi']);

    Livewire::actingAs($user)->test(MeetingShow::class, ['meeting' => $meeting])
        ->set("agendaFiles.{$item->id}", receiptPng())
        ->call('uploadAgendaFile', $item->id)
        ->assertHasNoErrors();

    $doc = MeetingDocument::sole();
    assertStoredWithMetadata('local', $doc->path, $doc->mime_type, $doc->size_bytes, $doc->original_name);
});

// In unit tests Livewire puts temp uploads on its own "tmp-for-tests" disk, so the
// Livewire tests above copy rather than move. These build a temp upload on the
// *same* disk as the target to reproduce the move that crashed in production.

function sameDiskTemporaryUpload(): TemporaryUploadedFile
{
    // The constructor prefixes the livewire-tmp directory itself.
    $name = Str::random(20).'-meta'.base64_encode('kvitto.png').'-.png';
    Storage::disk('local')->put("livewire-tmp/{$name}", UploadedFile::fake()->image('kvitto.png', 40, 40)->getContent());

    return new TemporaryUploadedFile($name, 'local');
}

test('StoredUpload reads metadata before a same-disk move', function () {
    $upload = StoredUpload::store(sameDiskTemporaryUpload(), 'local', 'expense-claims/2027', 'abc.png');

    Storage::disk('local')->assertExists('expense-claims/2027/abc.png');
    expect($upload->path)->toBe('expense-claims/2027/abc.png')
        ->and($upload->mimeType)->toBe('image/png')
        ->and($upload->sizeBytes)->toBeGreaterThan(0)
        ->and($upload->originalName)->toBe('kvitto.png');
});

test('StoredUpload deletes the stored file when persisting fails', function () {
    $upload = StoredUpload::store(sameDiskTemporaryUpload(), 'local', 'expense-claims/2027', 'abc.png');

    expect(fn () => $upload->persist(fn () => throw new RuntimeException('db down')))
        ->toThrow(RuntimeException::class, 'db down');

    Storage::disk('local')->assertMissing('expense-claims/2027/abc.png');
});
