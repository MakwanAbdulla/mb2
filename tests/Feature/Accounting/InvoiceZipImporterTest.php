<?php

use App\Models\Accounting\Vendor;
use App\Services\Accounting\InvoiceZipImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Redirect scratch-file writes away from the real storage disk — otherwise
    // CLI-run tests create files/dirs owned by the CLI user, unwritable by
    // www-data on the next real web request (this bit us once already).
    Storage::fake('local');
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function zipFakePng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
}

/** A real, pdftotext-readable PDF with the given lines of body text. */
function fakeInvoicePdf(array $lines): string
{
    $html = '<p>' . implode('<br>', array_map('e', $lines)) . '</p>';

    return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
}

function buildZip(array $entries): string
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

// ── import() — time limit ────────────────────────────────────────────────────

test('never lowers the time limit (an unlimited CLI run stays unlimited)', function () {
    // Regression: set_time_limit(33) for a one-file zip used to impose a 33-second
    // limit on the whole process, crashing unrelated tests (and cron runs) later.
    $previous = ini_get('max_execution_time');
    set_time_limit(0);

    try {
        app(InvoiceZipImporter::class)->import(buildZip(['invoice.pdf' => fakeInvoicePdf(['Leverantör AB'])]));

        expect(ini_get('max_execution_time'))->toBe('0');
    } finally {
        set_time_limit((int) $previous);
    }
});

// ── import() — extraction ────────────────────────────────────────────────────

test('extracts amount, date and org number from a real PDF', function () {
    $zipPath = buildZip([
        'invoice.pdf' => fakeInvoicePdf(['Leverantör AB', 'Org.nr 556677-8899', 'Fakturadatum: 2027-03-01', 'Att betala: 1 234,56 kr']),
    ]);
    $result = app(InvoiceZipImporter::class)->import($zipPath);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['skipped'])->toBeEmpty()
        ->and($result['candidates'][0]['amount_ore'])->toBe(123456)
        ->and($result['candidates'][0]['date'])->toBe('2027-03-01');
});

test('matches vendor by organisationsnummer and suggests their default account', function () {
    $account = \App\Models\Accounting\Account::create([
        'code' => '6110', 'name' => 'Kontorsmaterial', 'type' => 'expense', 'is_active' => true, 'is_group' => false,
    ]);
    Vendor::create(['code' => 'V1', 'name' => 'Leverantör AB', 'type' => 'external', 'org_number' => '556677-8899', 'default_account_id' => $account->id]);

    $zipPath = buildZip([
        'invoice.pdf' => fakeInvoicePdf(['Leverantör AB', 'Org.nr 556677-8899', 'Att betala: 500,00 kr']),
    ]);
    $result = app(InvoiceZipImporter::class)->import($zipPath);

    expect($result['candidates'][0]['matched_vendor_id'])->not->toBeNull()
        ->and($result['candidates'][0]['suggested_account_id'])->toBe($account->id)
        ->and($result['candidates'][0]['confidence'])->toBe('high');
});

test('leaves matched_vendor_id null when no vendor has that org number', function () {
    $zipPath = buildZip([
        'invoice.pdf' => fakeInvoicePdf(['Okänt Företag AB', 'Org.nr 999999-9999', 'Att betala: 500,00 kr']),
    ]);
    $result = app(InvoiceZipImporter::class)->import($zipPath);

    expect($result['candidates'][0]['matched_vendor_id'])->toBeNull()
        ->and($result['candidates'][0]['confidence'])->toBe('medium'); // amount found, vendor not
});

test('image files are attached without extraction (no local OCR)', function () {
    $zipPath = buildZip(['receipt.png' => zipFakePng()]);
    $result  = app(InvoiceZipImporter::class)->import($zipPath);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]['amount_ore'])->toBeNull()
        ->and($result['candidates'][0]['matched_vendor_id'])->toBeNull()
        ->and($result['candidates'][0]['error'])->toBeNull(); // not an error, just nothing to extract
});

test('skips unsupported file types without processing them', function () {
    $zipPath = buildZip([
        'invoice.pdf' => fakeInvoicePdf(['Att betala: 100,00 kr']),
        'readme.txt'  => 'not an invoice',
    ]);
    $result = app(InvoiceZipImporter::class)->import($zipPath);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['original_name'])->toBe('readme.txt');
});

test('ignores macOS metadata and directory entries', function () {
    $zipPath = buildZip([
        'invoices/'              => '',
        '__MACOSX/._invoice.pdf' => 'junk',
        '.DS_Store'              => 'junk',
        'invoices/invoice.pdf'   => fakeInvoicePdf(['Att betala: 100,00 kr']),
    ]);
    $result = app(InvoiceZipImporter::class)->import($zipPath);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['skipped'])->toBeEmpty();
});

test('discardBatch removes the scratch directory', function () {
    $zipPath = buildZip(['invoice.pdf' => fakeInvoicePdf(['Att betala: 100,00 kr'])]);
    $result  = app(InvoiceZipImporter::class)->import($zipPath);

    expect(Storage::disk('local')->exists($result['candidates'][0]['scratch_path']))->toBeTrue();

    app(InvoiceZipImporter::class)->discardBatch($result['batch_id']);

    expect(Storage::disk('local')->exists($result['candidates'][0]['scratch_path']))->toBeFalse();
});
