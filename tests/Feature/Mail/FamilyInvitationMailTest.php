<?php

use App\Mail\FamilyInvitationMail;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Mail::fake() never renders the mail, which is how the broken route in
 * #251 went unnoticed. These tests render it for real.
 */
test('family invitation mail renders with a link to the family tab on the dashboard', function () {
    $invited = Member::factory()->create(['first_name' => 'Kim']);
    $inviter = Member::factory()->create();

    $html = (new FamilyInvitationMail($invited, $inviter))->render();

    expect($html)
        ->toContain(route('dashboard').'#familj')
        ->toContain('Svara på inbjudan')
        ->toContain('Kim');
});

test('a family adult can invite a member with an account and the mail is sent', function () {
    config(['mail.default' => 'array']);

    $adminUser   = User::factory()->create();
    $adminMember = Member::factory()->active()->create(['user_id' => $adminUser->id]);
    $group       = FamilyGroup::factory()->create(['administrator_member_id' => $adminMember->id]);
    $adminMember->update(['family_group_id' => $group->id]);
    $group->membersWithPivot()->attach($adminMember, [
        'role'   => FamilyGroup::ROLE_ADULT,
        'status' => FamilyGroup::STATUS_ACTIVE,
    ]);

    $invitedUser   = User::factory()->create();
    $invitedMember = Member::factory()->active()->create([
        'user_id'       => $invitedUser->id,
        'date_of_birth' => now()->subYears(30),
    ]);

    Volt::actingAs($adminUser)
        ->test('family.manage')
        ->call('addMember', $invitedMember->id)
        ->assertHasNoErrors();

    $sent = app('mailer')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getOriginalMessage()->getHtmlBody())->toContain(route('dashboard').'#familj')
        ->and($invitedMember->fresh()->family_group_id)->toBe($group->id);
});

function fimAdmin(bool $withMember = true): User
{
    Permission::findOrCreate('members.manage');
    $user = User::factory()->create();
    $user->givePermissionTo('members.manage');

    if ($withMember) {
        Member::factory()->active()->create(['user_id' => $user->id]);
    }

    return $user;
}

function fimInviteAsAdmin(User $admin, FamilyGroup $group, Member $member)
{
    return Volt::actingAs($admin)
        ->test('admin.family-groups.index')
        ->call('selectGroup', $group->id)
        ->call('openAddMember', $group->id)
        // Set the id directly — selectAddMember() fills the search box, whose
        // query uses MySQL's CONCAT, which SQLite lacks.
        ->set('addMemberId', $member->id)
        ->call('confirmAddMember');
}

test('an admin can invite a member with an account to a family group and the mail is sent', function () {
    config(['mail.default' => 'array']);

    $group         = FamilyGroup::factory()->create();
    $invitedMember = Member::factory()->active()->create(['user_id' => User::factory()->create()->id]);

    fimInviteAsAdmin(fimAdmin(), $group, $invitedMember)->assertHasNoErrors();

    $sent = app('mailer')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getOriginalMessage()->getHtmlBody())->toContain(route('dashboard').'#familj')
        ->and($invitedMember->fresh()->family_group_id)->toBe($group->id);
});

test('an admin without a member record can still invite to a family group', function () {
    config(['mail.default' => 'array']);

    $group         = FamilyGroup::factory()->create();
    $invitedMember = Member::factory()->active()->create(['user_id' => User::factory()->create()->id]);

    fimInviteAsAdmin(fimAdmin(withMember: false), $group, $invitedMember)->assertHasNoErrors();

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});
