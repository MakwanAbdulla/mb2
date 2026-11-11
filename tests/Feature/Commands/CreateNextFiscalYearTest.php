<?php

use App\Models\Accounting\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('creates fiscal year for next year when none exists', function () {
    $nextYear = now()->year + 1;

    Artisan::call('accounting:create-fiscal-year', ['year' => $nextYear]);

    $fy = FiscalYear::where('name', (string) $nextYear)->first();
    expect($fy)->not->toBeNull()
        ->and($fy->starts_at->format('Y-m-d'))->toBe("{$nextYear}-01-01")
        ->and($fy->ends_at->format('Y-m-d'))->toBe("{$nextYear}-12-31")
        ->and($fy->is_locked)->toBeFalse();
});

test('does not create duplicate when fiscal year already exists', function () {
    $year = 2030;
    FiscalYear::create([
        'name'      => (string) $year,
        'starts_at' => "{$year}-01-01",
        'ends_at'   => "{$year}-12-31",
        'is_locked' => false,
    ]);

    Artisan::call('accounting:create-fiscal-year', ['year' => $year]);

    expect(FiscalYear::where('name', (string) $year)->count())->toBe(1);
});

test('--force flag is handled: skips creation when year already exists', function () {
    $year = 2031;
    FiscalYear::create([
        'name'      => (string) $year,
        'starts_at' => "{$year}-01-01",
        'ends_at'   => "{$year}-12-31",
        'is_locked' => false,
    ]);

    Artisan::call('accounting:create-fiscal-year', ['year' => $year, '--force' => true]);

    expect(FiscalYear::where('name', (string) $year)->count())->toBe(1);
});

test('defaults to next year when no year argument given', function () {
    $nextYear = now()->year + 1;

    Artisan::call('accounting:create-fiscal-year');

    expect(FiscalYear::where('name', (string) $nextYear)->exists())->toBeTrue();
});

test('created fiscal year covers full calendar year', function () {
    Artisan::call('accounting:create-fiscal-year', ['year' => 2040]);

    $fy = FiscalYear::where('name', '2040')->first();
    expect($fy->starts_at->month)->toBe(1)
        ->and($fy->starts_at->day)->toBe(1)
        ->and($fy->ends_at->month)->toBe(12)
        ->and($fy->ends_at->day)->toBe(31);
});
