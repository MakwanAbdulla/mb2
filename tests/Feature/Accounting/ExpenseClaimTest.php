<?php

use App\Models\Accounting\CostCenter;
use App\Models\Accounting\ExpenseClaim;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function ecMember(): Member
{
    $user = User::factory()->create();
    return Member::factory()->create(['user_id' => $user->id]);
}

function ecClaim(Member $member, array $attrs = []): ExpenseClaim
{
    return ExpenseClaim::create(array_merge([
        'member_id'            => $member->id,
        'description'          => 'Kontorsmaterial',
        'purchased_at'         => '2027-03-15',
        'amount_ore'           => 450_00,
        'status'               => ExpenseClaim::STATUS_SUBMITTED,
        'claim_type'           => ExpenseClaim::CLAIM_TYPE_REIMBURSEMENT,
        'submitted_at'         => now(),
        'receipt_disk'         => 'local',
        'receipt_path'         => 'expense-claims/2027/test.pdf',
        'receipt_original_name' => 'kvitto.pdf',
        'receipt_mime_type'    => 'application/pdf',
        'receipt_size_bytes'   => 12_345,
    ], $attrs));
}

// ── Status helpers ────────────────────────────────────────────────────────────

test('isSubmitted returns true for submitted status', function () {
    $claim = ecClaim(ecMember());
    expect($claim->isSubmitted())->toBeTrue();
});

test('isApproved returns true for approved status', function () {
    $claim = ecClaim(ecMember(), ['status' => ExpenseClaim::STATUS_APPROVED]);
    expect($claim->isApproved())->toBeTrue();
});

test('isRejected returns true for rejected status', function () {
    $claim = ecClaim(ecMember(), ['status' => ExpenseClaim::STATUS_REJECTED]);
    expect($claim->isRejected())->toBeTrue();
});

test('statusLabel returns Swedish labels', function () {
    $submitted = ecClaim(ecMember());
    $approved  = ecClaim(ecMember(), ['status' => ExpenseClaim::STATUS_APPROVED]);
    $rejected  = ecClaim(ecMember(), ['status' => ExpenseClaim::STATUS_REJECTED]);

    expect($submitted->statusLabel())->toBe('Inlämnat')
        ->and($approved->statusLabel())->toBe('Godkänt')
        ->and($rejected->statusLabel())->toBe('Avvisat');
});

// ── Claim type helpers ────────────────────────────────────────────────────────

test('isReimbursement returns true for reimbursement type', function () {
    $claim = ecClaim(ecMember(), ['claim_type' => ExpenseClaim::CLAIM_TYPE_REIMBURSEMENT]);
    expect($claim->isReimbursement())->toBeTrue();
});

test('isCardReceipt returns true for card_receipt type', function () {
    $claim = ecClaim(ecMember(), ['claim_type' => ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT]);
    expect($claim->isCardReceipt())->toBeTrue();
});

// ── Relationships ─────────────────────────────────────────────────────────────

test('claim belongs to member', function () {
    $member = ecMember();
    $claim  = ecClaim($member);

    expect($claim->member->id)->toBe($member->id);
});

test('approved claim stores reviewer and reviewed_at', function () {
    $member   = ecMember();
    $reviewer = User::factory()->create();
    $claim    = ecClaim($member, [
        'status'      => ExpenseClaim::STATUS_APPROVED,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
        'review_note' => 'Ser bra ut',
    ]);

    expect($claim->reviewedBy->id)->toBe($reviewer->id)
        ->and($claim->review_note)->toBe('Ser bra ut');
});

test('claim can have a cost center', function () {
    $costCenter = CostCenter::create(['name' => 'Styrelsen', 'code' => '10']);
    $member     = ecMember();
    $claim      = ecClaim($member, ['cost_center_id' => $costCenter->id]);

    expect($claim->costCenter->id)->toBe($costCenter->id);
});

// ── sizeForHumans ─────────────────────────────────────────────────────────────

test('sizeForHumans formats bytes correctly', function () {
    $member = ecMember();

    $bytes = ecClaim($member, ['receipt_size_bytes' => 500]);
    $kb    = ecClaim($member, ['receipt_size_bytes' => 2048]);
    $mb    = ecClaim($member, ['receipt_size_bytes' => 2_097_152]);

    expect($bytes->sizeForHumans())->toBe('500 B')
        ->and($kb->sizeForHumans())->toBe('2 KB')
        ->and($mb->sizeForHumans())->toBe('2 MB');
});

// ── buildReceiptPath ──────────────────────────────────────────────────────────

test('buildReceiptPath includes year and file extension', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $path = ExpenseClaim::buildReceiptPath('pdf');

    expect($path)->toContain((string) now()->year)
        ->and($path)->toEndWith('.pdf');
});
