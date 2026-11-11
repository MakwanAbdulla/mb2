<?php

use App\Models\Content\Mailing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('status helpers reflect current status', function () {
    $draft = Mailing::factory()->draft()->create();

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->isSending())->toBeFalse()
        ->and($draft->isSent())->toBeFalse()
        ->and($draft->isEditable())->toBeTrue();
});

test('sending status is not editable', function () {
    $mailing = Mailing::factory()->sending()->create();

    expect($mailing->isEditable())->toBeFalse()
        ->and($mailing->isSending())->toBeTrue();
});

test('sent status is not editable', function () {
    $mailing = Mailing::factory()->sent()->create();

    expect($mailing->isEditable())->toBeFalse()
        ->and($mailing->isSent())->toBeTrue();
});

test('scheduled status is editable', function () {
    $mailing = Mailing::factory()->create(['status' => Mailing::STATUS_SCHEDULED]);

    expect($mailing->isEditable())->toBeTrue()
        ->and($mailing->isScheduled())->toBeTrue();
});

test('mailing has recipients relationship', function () {
    $mailing = Mailing::factory()->create();

    expect($mailing->recipients()->count())->toBe(0);
});
