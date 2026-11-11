<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\Section;
use App\Models\WorkspaceAccount;
use App\Services\GoogleWorkspaceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Google Workspace');

new class extends Component {

    public ?string $delegateMailbox  = null;
    public string  $newDelegateEmail = '';

    public ?string $aliasMailbox  = null;
    public string  $newAliasInput = '';

    public ?string $editingEmail      = null;
    public string  $editDisplayName  = '';
    public string  $editDescription  = '';
    public ?int    $editSectionId    = null;
    public ?int    $editOrgRoleId    = null;
    public ?int    $editMemberId     = null;
    public string  $editMemberName   = '';
    public string  $editMemberSearch = '';

    // Provisionering + lösenordsåterställning (lösenord lagras i session, ej i snapshot)
    public bool $showPasswordBanner = false;

    // Skapa nytt funktionskonto
    public bool    $showCreateForm   = false;
    public string  $createEmail      = '';
    public string  $createName       = '';
    public string  $createDescription = '';
    public ?int    $createSectionId  = null;
    public ?int    $createOrgRoleId  = null;

    // Provisionera personkonto — combobox
    public string $provisionSearch       = '';
    public ?int   $provisionSelectedId   = null;
    public string $provisionSelectedName = '';
    public string $provisionEmail        = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('workspace.manage'), 403);
    }

    #[Computed]
    public function membersWithoutWorkspace(): \Illuminate\Support\Collection
    {
        return Member::where('status', Member::STATUS_ACTIVE)
            ->whereDoesntHave('workspaceAccount')
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
    }

    public function provisionMember(int $memberId, string $email): void
    {
        $member = Member::findOrFail($memberId);
        $result = app(GoogleWorkspaceService::class)->provisionWithPassword($member, $email);

        if (! $result) {
            session()->flash('ws_error', 'Kunde inte provisionera kontot. Kontrollera loggen.');
            return;
        }

        session()->put('ws_temp_pwd', [
            'email'     => $result['email'],
            'password'  => $result['password'],
            'is_new'    => true,
            'mail_sent' => $this->sendPasswordMail($member, $result['email'], $result['password'], true),
        ]);
        AuditLog::record('workspace_account_provisioned', 'workspace', $result['email'], $member);
        $this->showPasswordBanner      = true;
        $this->provisionSelectedId     = null;
        $this->provisionSelectedName   = '';
        $this->provisionEmail          = '';
        unset($this->allAccounts, $this->membersWithoutWorkspace, $this->provisionResults);
    }

    #[Computed]
    public function provisionResults(): \Illuminate\Support\Collection
    {
        if (mb_strlen($this->provisionSearch) < 1) {
            return collect();
        }
        $q = mb_strtolower($this->provisionSearch);
        return $this->membersWithoutWorkspace->filter(fn (Member $m) =>
            str_contains(mb_strtolower($m->full_name), $q) ||
            str_contains((string) $m->membership_number, $q)
        )->take(8);
    }

    public function selectProvisionMember(int $id, string $name): void
    {
        $this->provisionSelectedId   = $id;
        $this->provisionSelectedName = $name;
        $this->provisionSearch       = '';
        $member = $this->membersWithoutWorkspace->firstWhere('id', $id);
        $this->provisionEmail = $member
            ? app(GoogleWorkspaceService::class)->resolveEmail($member)
            : '';
        unset($this->provisionResults);
    }

    public function clearProvisionSelection(): void
    {
        $this->provisionSelectedId   = null;
        $this->provisionSelectedName = '';
        $this->provisionSearch       = '';
        $this->provisionEmail        = '';
        unset($this->provisionResults);
    }

    public function provisionSelected(): void
    {
        if (! $this->provisionSelectedId) {
            return;
        }
        $domain = config('services.google_workspace.domain');
        $this->validate(
            ['provisionEmail' => ['required', 'email', "ends_with:@{$domain}"]],
            ['provisionEmail.ends_with' => "Adressen måste sluta på @{$domain}."],
            ['provisionEmail' => 'e-postadress'],
        );
        $this->provisionMember($this->provisionSelectedId, $this->provisionEmail);
    }

    public function resetPassword(string $email): void
    {
        $password = app(GoogleWorkspaceService::class)->resetPassword($email);

        if (! $password) {
            session()->flash('ws_error', 'Kunde inte återställa lösenordet. Kontrollera loggen.');
            return;
        }

        $account  = WorkspaceAccount::personal()->where('email', $email)->with('accountable')->first();
        $member   = $account?->accountable;
        $mailSent = $member ? $this->sendPasswordMail($member, $email, $password, false) : false;

        session()->put('ws_temp_pwd', [
            'email'     => $email,
            'password'  => $password,
            'is_new'    => false,
            'mail_sent' => $mailSent,
        ]);
        AuditLog::record('workspace_password_reset', 'workspace', $email, null);
        $this->showPasswordBanner = true;
    }

    public function dismissTempPassword(): void
    {
        session()->forget('ws_temp_pwd');
        $this->showPasswordBanner = false;
    }

    private function sendPasswordMail(Member $member, string $wsEmail, string $password, bool $isNew): bool
    {
        // Send to personal address only — never to the workspace account itself
        $personalEmail = $member->user?->email;
        if (! $personalEmail || str_ends_with(strtolower($personalEmail), '@' . config('services.google_workspace.domain'))) {
            return false;
        }

        try {
            \Illuminate\Support\Facades\Mail::to($personalEmail)
                ->send(new \App\Mail\WorkspacePasswordMail($member, $wsEmail, $password, $isNew));
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('WorkspacePasswordMail failed', ['member_id' => $member->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    #[Computed]
    public function allAccounts(): array
    {
        $ws = app(GoogleWorkspaceService::class);

        $wsAccounts = $ws->listUsers() ?? [];

        $accountsByEmail = WorkspaceAccount::with('accountable')
            ->get()->keyBy('email');

        return array_map(function ($wsUser) use ($accountsByEmail) {
            $email   = $wsUser['email'];
            $account = $accountsByEmail->get($email);
            $member  = ($account?->accountable_type === 'member') ? $account->accountable : null;

            return [
                'email'               => $email,
                'full_name'           => $wsUser['full_name'],
                'suspended'           => $wsUser['suspended'],
                'type'                => $member ? 'personal' : 'shared',
                'member'              => $member,
                'account'             => $account,
                'pending_deletion_at' => $account?->pending_deletion_at,
            ];
        }, $wsAccounts);
    }

    #[Computed]
    public function delegates(): ?array
    {
        if (! $this->delegateMailbox) {
            return null;
        }
        return app(GoogleWorkspaceService::class)->getDelegates($this->delegateMailbox) ?? [];
    }

    #[Computed]
    public function mailboxAliases(): ?array
    {
        if (! $this->aliasMailbox) {
            return null;
        }
        return app(GoogleWorkspaceService::class)->getAliases($this->aliasMailbox) ?? [];
    }

    #[Computed]
    public function editMemberResults(): \Illuminate\Support\Collection
    {
        if (mb_strlen($this->editMemberSearch) < 1) {
            return collect();
        }
        $q = mb_strtolower($this->editMemberSearch);
        return Member::where('status', Member::STATUS_ACTIVE)
            ->where(fn ($query) => $query
                ->whereDoesntHave('workspaceAccount')
                ->orWhereHas('workspaceAccount', fn ($q) => $q->where('email', $this->editingEmail))
            )
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->filter(fn (Member $m) =>
                str_contains(mb_strtolower($m->full_name), $q) ||
                str_contains((string) $m->membership_number, $q)
            )->take(8);
    }

    #[Computed]
    public function pendingNameChanges(): \Illuminate\Support\Collection
    {
        return AuditLog::where('action', 'member_name_changed')
            ->where('area', 'workspace')
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->get()
            ->filter(function ($change) {
                $wsEmail = $change->changes['workspace_email'] ?? null;
                if (! $wsEmail) {
                    return true;
                }
                return ! AuditLog::where('action', 'workspace_alias_added')
                    ->where('area', 'workspace')
                    ->where('subject', 'like', $wsEmail . ' ←%')
                    ->where('created_at', '>', $change->created_at)
                    ->exists();
            });
    }

    #[Computed]
    public function sections(): \Illuminate\Support\Collection
    {
        return Section::orderBy('name')->get();
    }

    #[Computed]
    public function orgRoles(): \Illuminate\Support\Collection
    {
        return OrganizationalRole::orderBy('name')->get();
    }

    public function openDelegates(string $email): void
    {
        $this->delegateMailbox  = $email;
        $this->newDelegateEmail = '';
        unset($this->delegates);
    }

    public function closeDelegates(): void
    {
        $this->delegateMailbox = null;
    }

    public function openAliases(string $email): void
    {
        $this->aliasMailbox  = $email;
        $this->newAliasInput = '';
        unset($this->mailboxAliases);
    }

    public function closeAliases(): void
    {
        $this->aliasMailbox = null;
    }

    public function addAlias(): void
    {
        $domain = config('services.google_workspace.domain');
        $this->validate(
            ['newAliasInput' => ['required', 'regex:/^[a-z0-9._-]+$/i']],
            ['newAliasInput.regex' => 'Endast bokstäver, siffror, punkt och bindestreck.'],
        );

        $alias = $this->newAliasInput . '@' . $domain;
        $ok    = app(GoogleWorkspaceService::class)->addAlias($this->aliasMailbox, $alias);

        if ($ok) {
            AuditLog::record('workspace_alias_added', 'workspace', $this->aliasMailbox . ' ← ' . $alias, null);
            $this->newAliasInput = '';
            unset($this->mailboxAliases);
            unset($this->pendingNameChanges);
        } else {
            $this->addError('newAliasInput', 'Kunde inte lägga till alias. Det kanske redan finns.');
        }
    }

    public function removeAlias(string $alias): void
    {
        app(GoogleWorkspaceService::class)->removeAlias($this->aliasMailbox, $alias);
        AuditLog::record('workspace_alias_removed', 'workspace', $this->aliasMailbox . ' — ' . $alias, null);
        unset($this->mailboxAliases);
    }

    public function applyNameChange(int $auditLogId): void
    {
        $change  = AuditLog::findOrFail($auditLogId);
        $wsEmail = $change->changes['workspace_email'] ?? null;
        $newName = $change->changes['new_name'] ?? null;
        $oldName = $change->changes['old_name'] ?? null;

        if (! $wsEmail || ! $newName || ! $oldName) {
            return;
        }

        $ws = app(GoogleWorkspaceService::class);

        $ws->renameUser($wsEmail, $newName);

        $oldParts  = explode(' ', $oldName, 2);
        $oldAlias  = $ws->emailFromName($oldParts[0], $oldParts[1] ?? $oldParts[0]);

        if ($ws->addAlias($wsEmail, $oldAlias)) {
            AuditLog::record('workspace_alias_added', 'workspace', $wsEmail . ' ← ' . $oldAlias, null);
        }

        unset($this->mailboxAliases, $this->pendingNameChanges, $this->allAccounts);
    }

    public function selectEditMember(int $id, string $name): void
    {
        $this->editMemberId     = $id;
        $this->editMemberName   = $name;
        $this->editMemberSearch = '';
        unset($this->editMemberResults);
    }

    public function clearEditMember(): void
    {
        $this->editMemberId     = null;
        $this->editMemberName   = '';
        $this->editMemberSearch = '';
        unset($this->editMemberResults);
    }

    public function addDelegate(): void
    {
        $this->validate(['newDelegateEmail' => 'required|email']);

        $ok = app(GoogleWorkspaceService::class)->addDelegate(
            $this->delegateMailbox,
            $this->newDelegateEmail
        );

        if ($ok) {
            $this->newDelegateEmail = '';
            unset($this->delegates);
        } else {
            $this->addError('newDelegateEmail', 'Kunde inte lägga till delegat. Kontrollera att adressen är ett aktivt Workspace-konto.');
        }
    }

    public function removeDelegate(string $delegateEmail): void
    {
        app(GoogleWorkspaceService::class)->removeDelegate($this->delegateMailbox, $delegateEmail);
        unset($this->delegates);
    }

    public function openEdit(string $email, string $displayName): void
    {
        $account = WorkspaceAccount::with('accountable')->where('email', $email)->first();
        $member  = ($account?->accountable_type === 'member') ? $account->accountable : null;

        $this->editingEmail     = $email;
        $this->editDisplayName  = $account?->display_name ?: $displayName;
        $this->editDescription  = $account?->description ?? '';
        $this->editMemberId     = $member?->id;
        $this->editMemberName   = $member?->full_name ?? '';
        $this->editMemberSearch = '';

        if ($account?->accountable_type === 'org_role') {
            $this->editOrgRoleId = $account->accountable_id;
            $this->editSectionId = null;
        } elseif ($account?->accountable_type === 'section') {
            $this->editSectionId = $account->accountable_id;
            $this->editOrgRoleId = null;
        } else {
            $this->editSectionId = null;
            $this->editOrgRoleId = null;
        }

        unset($this->editMemberResults);
    }

    public function saveSharedAccount(): void
    {
        if (! $this->editingEmail) {
            return;
        }

        $this->validate([
            'editDisplayName' => 'nullable|string|max:120',
            'editDescription' => 'nullable|string|max:500',
            'editSectionId'   => 'nullable|integer|exists:sections,id',
            'editOrgRoleId'   => 'nullable|integer|exists:organizational_roles,id',
        ]);

        $account = WorkspaceAccount::firstOrCreate(['email' => $this->editingEmail]);
        $oldOrgRoleId = ($account->accountable_type === 'org_role') ? $account->accountable_id : null;

        if ($this->editMemberId) {
            // Re-linking to a (different) member
            if ($account->accountable_type !== 'member' || $account->accountable_id !== $this->editMemberId) {
                $account->update([
                    'accountable_type' => 'member',
                    'accountable_id'   => $this->editMemberId,
                    'display_name'     => null,
                    'description'      => null,
                ]);
                AuditLog::record('workspace_account_linked', 'workspace', $this->editingEmail . ' → ' . $this->editMemberName, null);
            }
        } else {
            // Shared account — determine new accountable
            $newType = null;
            $newId   = null;
            if ($this->editOrgRoleId) {
                $newType = 'org_role';
                $newId   = $this->editOrgRoleId;
            } elseif ($this->editSectionId) {
                $newType = 'section';
                $newId   = $this->editSectionId;
            }

            $oldDisplayName = $account->display_name;
            $account->update([
                'display_name'     => $this->editDisplayName ?: null,
                'description'      => $this->editDescription ?: null,
                'accountable_type' => $newType,
                'accountable_id'   => $newId,
            ]);

            if ($this->editDisplayName && $this->editDisplayName !== $oldDisplayName) {
                app(GoogleWorkspaceService::class)->renameUser($this->editingEmail, $this->editDisplayName);
            }
        }

        $newOrgRoleId = ($account->fresh()->accountable_type === 'org_role') ? $account->accountable_id : null;
        if ($oldOrgRoleId !== $newOrgRoleId) {
            app(GoogleWorkspaceService::class)->syncRoleDelegates();
        }

        AuditLog::record('workspace_account_updated', 'workspace', $this->editingEmail, null);
        $this->editingEmail = null;
        unset($this->allAccounts, $this->membersWithoutWorkspace, $this->provisionResults);
    }

    public function cancelEdit(): void
    {
        $this->editingEmail = null;
    }

    public function scheduleDeleteAccount(string $email): void
    {
        abort_unless(Auth::user()->hasPermissionTo('workspace.manage'), 403);

        $ws = app(GoogleWorkspaceService::class);

        // Only suspend if not already suspended
        if (! $ws->isSuspended($email)) {
            if (! $ws->suspendAccount($email)) {
                session()->flash('ws_error', "Kunde inte stänga av kontot {$email}. Kontrollera loggen.");
                return;
            }
        }

        WorkspaceAccount::updateOrCreate(
            ['email' => $email],
            ['pending_deletion_at' => now()->addDays(5)]
        );

        AuditLog::record('workspace_account_deletion_scheduled', 'workspace', $email, null);
        unset($this->allAccounts);
    }

    public function cancelDeleteAccount(string $email): void
    {
        abort_unless(Auth::user()->hasPermissionTo('workspace.manage'), 403);

        app(GoogleWorkspaceService::class)->restoreAccount($email);

        WorkspaceAccount::where('email', $email)->update(['pending_deletion_at' => null]);

        AuditLog::record('workspace_account_deletion_cancelled', 'workspace', $email, null);
        unset($this->allAccounts);
    }

    public function openCreate(): void
    {
        $this->createEmail       = '';
        $this->createName        = '';
        $this->createDescription = '';
        $this->createSectionId   = null;
        $this->createOrgRoleId   = null;
        $this->showCreateForm    = true;
    }

    public function cancelCreate(): void
    {
        $this->showCreateForm = false;
    }

    public function createAccount(): void
    {
        $domain = config('services.google_workspace.domain');
        $this->validate([
            'createEmail' => ['required', 'email', 'ends_with:@' . $domain],
            'createName'  => ['required', 'string', 'max:120'],
            'createDescription' => ['nullable', 'string', 'max:500'],
            'createSectionId'   => ['nullable', 'integer', 'exists:sections,id'],
            'createOrgRoleId'   => ['nullable', 'integer', 'exists:organizational_roles,id'],
        ], [], [
            'createEmail' => 'e-postadress',
            'createName'  => 'visningsnamn',
        ]);

        $ws = app(GoogleWorkspaceService::class);

        if (! $ws->createSharedAccount($this->createEmail, $this->createName, $this->createDescription ?: null)) {
            $this->addError('createEmail', 'Kunde inte skapa kontot. Kontrollera att adressen inte redan finns.');
            return;
        }

        $newType = null;
        $newId   = null;
        if ($this->createOrgRoleId) {
            $newType = 'org_role';
            $newId   = $this->createOrgRoleId;
        } elseif ($this->createSectionId) {
            $newType = 'section';
            $newId   = $this->createSectionId;
        }

        WorkspaceAccount::create([
            'email'            => $this->createEmail,
            'display_name'     => $this->createName,
            'description'      => $this->createDescription ?: null,
            'accountable_type' => $newType,
            'accountable_id'   => $newId,
        ]);

        AuditLog::record('workspace_account_created', 'workspace', $this->createEmail, null);
        $this->showCreateForm = false;
        unset($this->allAccounts);
    }
}; ?>

<div>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Google Workspace</h1>
            <button wire:click="openCreate" class="btn btn-primary btn-sm">
                <x-bootstrap-icon name="plus" class="size-4" />
                Nytt funktionskonto
            </button>
        </div>

        {{-- Temp-lösenord efter provisionering/återställning --}}
        @if($showPasswordBanner && ($wsPwd = session('ws_temp_pwd')))
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-semibold text-amber-900 dark:text-amber-200">
                            {{ $wsPwd['is_new'] ? 'Konto skapat' : 'Lösenord återställt' }} — spara detta nu
                        </p>
                        <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">
                            Konto: <span class="font-mono font-semibold">{{ $wsPwd['email'] }}</span>
                        </p>
                        <p class="mt-0.5 text-sm text-amber-800 dark:text-amber-300">
                            Tillfälligt lösenord: <span class="font-mono font-semibold text-lg tracking-widest">{{ $wsPwd['password'] }}</span>
                        </p>
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                            {{ $wsPwd['is_new'] ? 'Kontot kräver lösenordsbyte vid första inloggning.' : 'Lösenordsbyte krävs vid nästa inloggning.' }}
                            @if($wsPwd['mail_sent']) Välkomstmail skickat till personlig adress. @else Ingen personlig e-post — vidarebefordra uppgifterna manuellt. @endif
                        </p>
                    </div>
                    <button wire:click="dismissTempPassword" class="text-amber-600 hover:text-amber-800 dark:text-amber-400">
                        <x-bootstrap-icon name="x-lg" class="size-5" />
                    </button>
                </div>
            </div>
        @endif

        @if(session('ws_error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                {{ session('ws_error') }}
            </div>
        @endif

        @if($this->pendingNameChanges->isNotEmpty())
            <div class="rounded-xl border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20">
                <div class="flex items-center gap-2 border-b border-amber-200 px-4 py-2.5 dark:border-amber-700/50">
                    <x-bootstrap-icon name="person-fill-exclamation" class="size-4 text-amber-600 dark:text-amber-400" />
                    <span class="text-sm font-semibold text-amber-900 dark:text-amber-200">Väntande namnbyten</span>
                    <span class="ml-auto text-xs text-amber-700 dark:text-amber-400">Visas i 30 dagar</span>
                </div>
                <ul class="divide-y divide-amber-100 dark:divide-amber-800/40">
                    @foreach($this->pendingNameChanges as $change)
                        @php $wsEmail = $change->changes['workspace_email'] ?? null; @endphp
                        <li class="flex items-center gap-3 px-4 py-2.5">
                            <div class="min-w-0 flex-1 text-sm">
                                <span class="font-medium text-amber-900 dark:text-amber-200">{{ $change->changes['old_name'] ?? '' }}</span>
                                <x-bootstrap-icon name="arrow-right" class="mx-1 inline size-3 text-amber-600" />
                                <span class="font-medium text-amber-900 dark:text-amber-200">{{ $change->changes['new_name'] ?? '' }}</span>
                                @if($wsEmail)
                                    <span class="ml-2 font-mono text-xs text-amber-700 dark:text-amber-400">{{ $wsEmail }}</span>
                                @endif
                                <span class="ml-2 text-xs text-amber-600 dark:text-amber-500">{{ $change->created_at->diffForHumans() }}</span>
                            </div>
                            @if($wsEmail)
                                <button wire:click="applyNameChange({{ $change->id }})"
                                        wire:confirm="Byt namn i Google Workspace och lägg till gammalt namn som alias?"
                                        class="shrink-0 rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-600 dark:bg-amber-900/40 dark:text-amber-300 dark:hover:bg-amber-800/40">
                                    Tillämpa namnbyte
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Konto</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Koppling</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Delegates</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Alias</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($this->allAccounts as $account)
                        <tr class="bg-white dark:bg-zinc-900 {{ $account['suspended'] ? 'opacity-60' : '' }}">

                            {{-- Konto --}}
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $account['full_name'] }}
                                    @if($account['suspended'])
                                        <span class="ml-1.5 rounded-full bg-red-100 px-1.5 py-0.5 text-xs text-red-700 dark:bg-red-900/30 dark:text-red-400">Avstängt</span>
                                    @endif
                                </div>
                                <div class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $account['email'] }}</div>
                            </td>

                            {{-- Koppling --}}
                            <td class="px-4 py-3">
                                @if($account['member'])
                                    <a href="{{ route('admin.members.show', $account['member']) }}" wire:navigate
                                       class="text-complement hover:underline dark:text-complement-light">
                                        {{ $account['member']->full_name }}
                                    </a>
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">#{{ $account['member']->membership_number }}</div>
                                @elseif($account['account']?->accountable_type === 'org_role')
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $account['account']->accountable?->name }}</span>
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Organisationsroll</div>
                                @elseif($account['account']?->accountable_type === 'section')
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $account['account']->accountable?->name }}</span>
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Sektion</div>
                                @elseif($account['account']?->description)
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $account['account']->description }}</span>
                                @else
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">—</span>
                                @endif
                            </td>

                            {{-- Delegates --}}
                            <td class="px-4 py-3">
                                @if($delegateMailbox === $account['email'])
                                    <div class="space-y-2">
                                        @forelse($this->delegates ?? [] as $d)
                                            <div class="flex items-center justify-between gap-2">
                                                <div>
                                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $d['email'] }}</span>
                                                    @if($d['status'] === 'pending')
                                                        <span class="ml-1 text-xs text-amber-500">väntar</span>
                                                    @endif
                                                </div>
                                                <button wire:click="removeDelegate('{{ $d['email'] }}')"
                                                        wire:confirm="Ta bort {{ $d['email'] }} som delegat?"
                                                        class="text-xs text-red-500 hover:text-red-700">Ta bort</button>
                                            </div>
                                        @empty
                                            <p class="text-xs text-zinc-400 dark:text-zinc-500">Inga delegates.</p>
                                        @endforelse

                                        <div class="flex gap-1.5 pt-1">
                                            <input wire:model="newDelegateEmail" type="email"
                                                   placeholder="namn@armagedon.se"
                                                   class="inp flex-1 py-1 text-xs" />
                                            <button wire:click="addDelegate" class="btn btn-primary btn-sm">Lägg till</button>
                                        </div>
                                        @error('newDelegateEmail')
                                            <p class="text-xs text-red-500">{{ $message }}</p>
                                        @enderror
                                        <button wire:click="closeDelegates" class="text-xs text-zinc-400 hover:text-zinc-600">Stäng</button>
                                    </div>
                                @else
                                    <button wire:click="openDelegates('{{ $account['email'] }}')"
                                            class="text-xs text-complement hover:underline dark:text-complement-light">
                                        Hantera
                                    </button>
                                @endif
                            </td>

                            {{-- Alias --}}
                            <td class="px-4 py-3">
                                @if($aliasMailbox === $account['email'])
                                    <div class="space-y-2">
                                        @forelse($this->mailboxAliases ?? [] as $alias)
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $alias }}</span>
                                                <button wire:click="removeAlias('{{ $alias }}')"
                                                        wire:confirm="Ta bort alias {{ $alias }}?"
                                                        class="text-xs text-red-500 hover:text-red-700">Ta bort</button>
                                            </div>
                                        @empty
                                            <p class="text-xs text-zinc-400 dark:text-zinc-500">Inga alias.</p>
                                        @endforelse

                                        <div class="flex gap-1.5 pt-1">
                                            <input wire:model="newAliasInput" type="text"
                                                   placeholder="localpart"
                                                   class="inp flex-1 py-1 font-mono text-xs" />
                                            <button wire:click="addAlias" class="btn btn-primary btn-sm">Lägg till</button>
                                        </div>
                                        @error('newAliasInput')
                                            <p class="text-xs text-red-500">{{ $message }}</p>
                                        @enderror
                                        <button wire:click="closeAliases" class="text-xs text-zinc-400 hover:text-zinc-600">Stäng</button>
                                    </div>
                                @else
                                    <button wire:click="openAliases('{{ $account['email'] }}')"
                                            class="text-xs text-complement hover:underline dark:text-complement-light">
                                        Hantera
                                    </button>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <button wire:click="resetPassword('{{ $account['email'] }}')"
                                            wire:confirm="Återställ lösenordet för {{ $account['email'] }}?"
                                            class="text-xs text-amber-600 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-200">
                                        Återställ lösenord
                                    </button>
                                    <button wire:click="openEdit('{{ $account['email'] }}', '{{ addslashes($account['full_name']) }}')"
                                            class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                        Redigera
                                    </button>
                                    @if($account['pending_deletion_at'])
                                        <span class="text-xs text-red-500 dark:text-red-400">
                                            Raderas {{ \Carbon\Carbon::parse($account['pending_deletion_at'])->locale('sv')->diffForHumans() }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Provisionera personkonto --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                    Provisionera personkonto
                    @if($this->membersWithoutWorkspace->isNotEmpty())
                        <span class="ml-2 text-xs font-normal text-zinc-400 dark:text-zinc-500">
                            {{ $this->membersWithoutWorkspace->count() }} aktiva medlemmar utan konto
                        </span>
                    @endif
                </h2>
            </div>
            <div class="p-4">
                @if($this->membersWithoutWorkspace->isEmpty())
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Alla aktiva medlemmar har Workspace-konton.</p>
                @else
                    @if($provisionSelectedId)
                        {{-- Vald medlem — visa namn + redigerbar e-postadress --}}
                        <div class="space-y-3">
                            <div class="flex items-center gap-2">
                                <x-bootstrap-icon name="person-check" class="size-4 text-zinc-400 dark:text-zinc-500" />
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $provisionSelectedName }}</span>
                                <button wire:click="clearProvisionSelection" type="button"
                                        class="ml-auto text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                    Byt
                                </button>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-1">
                                    <input wire:model="provisionEmail"
                                           type="email"
                                           class="inp w-full font-mono text-sm"
                                           placeholder="fornamn.efternamn@armagedon.se" />
                                    @error('provisionEmail')
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                                <flux:button wire:click="provisionSelected"
                                             wire:loading.attr="disabled"
                                             wire:target="provisionSelected"
                                             size="sm">
                                    Skapa konto
                                </flux:button>
                            </div>
                        </div>
                    @else
                        {{-- Sökfält --}}
                        <div class="relative" x-data="{ open: false }">
                            <input wire:model.live.debounce.150ms="provisionSearch"
                                   type="text"
                                   placeholder="Sök på namn eller medlemsnummer…"
                                   class="inp w-full"
                                   x-on:focus="open = true"
                                   x-on:blur="setTimeout(() => open = false, 150)"
                                   autocomplete="off" />

                            @if($this->provisionResults->isNotEmpty())
                                <div x-show="open" x-cloak
                                     class="absolute left-0 right-0 top-full z-10 mt-1 max-h-60 overflow-y-auto rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                                    @foreach($this->provisionResults as $m)
                                        <button wire:click="selectProvisionMember({{ $m->id }}, '{{ addslashes($m->full_name) }}')"
                                                type="button"
                                                class="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                            <span class="text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">#{{ $m->membership_number }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @elseif(mb_strlen($provisionSearch) >= 1)
                                <div x-show="open" x-cloak
                                     class="absolute left-0 right-0 top-full z-10 mt-1 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-400 shadow-lg dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-500">
                                    Inga träffar.
                                </div>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Skapa funktionskonto --}}
    @if($showCreateForm)
        <div class="fixed inset-0 z-40 flex justify-end">
            <div class="absolute inset-0 bg-black/30 dark:bg-black/50" wire:click="cancelCreate"></div>
            <div class="relative z-50 w-full max-w-sm overflow-y-auto bg-white shadow-xl dark:bg-zinc-900 sm:max-w-md">
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">Nytt funktionskonto</h2>
                    <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">Skapar ett delat konto i Google Workspace.</p>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="lbl" for="createEmail">E-postadress</label>
                        <input id="createEmail" wire:model="createEmail" type="email"
                               placeholder="fakturor@armagedon.se" class="inp mt-1" />
                        @error('createEmail') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl" for="createName">Visningsnamn</label>
                        <input id="createName" wire:model="createName" type="text"
                               placeholder="Fakturor Armagedon" class="inp mt-1" />
                        @error('createName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl" for="createDescription">Beskrivning <span class="font-normal text-zinc-400">(valfri)</span></label>
                        <textarea id="createDescription" wire:model="createDescription" rows="2" class="inp mt-1 text-sm"
                                  placeholder="t.ex. Inkommande leverantörsfakturor"></textarea>
                    </div>
                    <div>
                        <label class="lbl" for="createOrgRoleId">Organisationsroll <span class="font-normal text-zinc-400">(valfri)</span></label>
                        <select id="createOrgRoleId" wire:model.number="createOrgRoleId" class="inp mt-1">
                            <option value="">— Ingen —</option>
                            @foreach($this->orgRoles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="lbl" for="createSectionId">Sektion <span class="font-normal text-zinc-400">(valfri)</span></label>
                        <select id="createSectionId" wire:model.number="createSectionId" class="inp mt-1">
                            <option value="">— Ingen —</option>
                            @foreach($this->sections as $section)
                                <option value="{{ $section->id }}">{{ $section->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex gap-3 border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <button wire:click="createAccount" class="btn btn-primary">Skapa konto</button>
                    <button wire:click="cancelCreate" class="btn btn-ghost">Avbryt</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Redigera konto --}}
    @if($editingEmail)
        <div class="fixed inset-0 z-40 flex justify-end">
            <div class="absolute inset-0 bg-black/30 dark:bg-black/50" wire:click="cancelEdit"></div>
            <div class="relative z-50 w-full max-w-sm overflow-y-auto bg-white shadow-xl dark:bg-zinc-900 sm:max-w-md">
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">Redigera konto</h2>
                    <p class="mt-0.5 font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $editingEmail }}</p>
                </div>
                <div class="space-y-5 px-6 py-5">

                    {{-- Koppling till medlem --}}
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Koppling till medlem</p>
                        @if($editMemberId)
                            <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                                <span class="flex-1 text-sm text-zinc-900 dark:text-white">{{ $editMemberName }}</span>
                                <button wire:click="clearEditMember" type="button"
                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                    Ta bort
                                </button>
                            </div>
                        @else
                            <div class="relative" x-data="{ open: false }">
                                <input wire:model.live.debounce.150ms="editMemberSearch"
                                       type="text"
                                       placeholder="Sök på namn eller medlemsnummer…"
                                       class="inp w-full"
                                       x-on:focus="open = true"
                                       x-on:blur="setTimeout(() => open = false, 150)"
                                       autocomplete="off" />
                                @if($this->editMemberResults->isNotEmpty())
                                    <div x-show="open" x-cloak
                                         class="absolute left-0 right-0 top-full z-10 mt-1 max-h-48 overflow-y-auto rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                                        @foreach($this->editMemberResults as $m)
                                            <button wire:click="selectEditMember({{ $m->id }}, '{{ addslashes($m->full_name) }}')"
                                                    type="button"
                                                    class="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                                <span class="text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                                                <span class="text-xs text-zinc-400 dark:text-zinc-500">#{{ $m->membership_number }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif(mb_strlen($editMemberSearch) >= 1)
                                    <div x-show="open" x-cloak
                                         class="absolute left-0 right-0 top-full z-10 mt-1 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-400 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                                        Inga träffar.
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Funktionskonto-metadata --}}
                    <div class="space-y-4 border-t border-zinc-100 pt-5 dark:border-zinc-700">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Funktionskonto</p>
                        <div>
                            <label class="lbl" for="editDisplayName">Visningsnamn <span class="font-normal text-zinc-400">(valfritt)</span></label>
                            <input id="editDisplayName" wire:model="editDisplayName" type="text" class="inp mt-1" />
                            @error('editDisplayName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lbl" for="editDescription">Beskrivning</label>
                            <textarea id="editDescription" wire:model="editDescription" rows="2" class="inp mt-1 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="lbl" for="editOrgRoleId">Organisationsroll</label>
                            <select id="editOrgRoleId" wire:model.number="editOrgRoleId" class="inp mt-1">
                                <option value="">— Ingen —</option>
                                @foreach($this->orgRoles as $role)
                                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lbl" for="editSectionId">Sektion</label>
                            <select id="editSectionId" wire:model.number="editSectionId" class="inp mt-1">
                                <option value="">— Ingen —</option>
                                @foreach($this->sections as $section)
                                    <option value="{{ $section->id }}">{{ $section->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <div class="flex gap-3">
                        <button wire:click="saveSharedAccount" class="btn btn-primary">Spara</button>
                        <button wire:click="cancelEdit" class="btn btn-ghost">Avbryt</button>
                    </div>
                    @php $editingAccount = collect($this->allAccounts)->firstWhere('email', $editingEmail); $pendingDeletion = $editingAccount['pending_deletion_at'] ?? null; @endphp
                    @if($pendingDeletion)
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-red-500 dark:text-red-400">
                                Raderas {{ \Carbon\Carbon::parse($pendingDeletion)->locale('sv')->diffForHumans() }}
                            </span>
                            <button wire:click="cancelDeleteAccount('{{ $editingEmail }}')"
                                    wire:confirm="Avbryt schemalagd radering av {{ $editingEmail }}?"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                Avbryt radering
                            </button>
                        </div>
                    @else
                        <button wire:click="scheduleDeleteAccount('{{ $editingEmail }}')"
                                wire:confirm="Stänger av {{ $editingEmail }} nu och raderar det permanent om 5 dagar. Fortsätta?"
                                class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                            Radera konto
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif

</div>
