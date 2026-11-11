<?php

use App\Mail\MemberInvitationMail;
use App\Models\AuditLog;
use App\Models\Content\MailLog;
use App\Models\Membership\Member;
use App\Models\User;
use App\Services\GoogleWorkspaceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new class extends Component {
    public Member $member;
    public array  $assignedRoles = [];

    public function mount(Member $member): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('members.view'), 403);

        $this->member = $member->load([
            'user',
            'familyGroup.membersWithPivot',
            'familyGroup.pendingInvitations',
            'familyGroup.administrator',
            'membershipPeriods' => fn ($q) => $q->orderByDesc('year'),
            'sections'          => fn ($q) => $q->wherePivotNull('left_at'),
            'sectionRoles'      => fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))->with('section'),
            'organizationalRoleMemberships.period.organizationalRole',
        ]);

        $this->assignedRoles = $member->user?->roles->pluck('name')->toArray() ?? [];
    }

    #[Computed]
    public function allRoles()
    {
        return Role::orderBy('name')->get();
    }

    public function saveRoles(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('users.manage-roles'), 403);

        $user = $this->member->user;
        if (! $user) {
            return;
        }

        // Prevent removing admin from yourself
        if ($user->id === Auth::id() && ! in_array('admin', $this->assignedRoles, true) && $user->hasRole('admin')) {
            $this->addError('assignedRoles', 'Du kan inte ta bort admin-rollen från dig själv.');
            return;
        }

        $user->syncRoles($this->assignedRoles);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        AuditLog::record('user_roles_synced', 'members', $this->member->full_name . ' — ' . implode(', ', $this->assignedRoles ?: ['(inga)']), $user);

        $this->member->refresh();
        $this->member->load('user');
    }

    public function statusLabel(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'Väntar',
            Member::STATUS_ACTIVE   => 'Aktiv',
            Member::STATUS_INACTIVE => 'Inaktiv',
            Member::STATUS_EXPIRED  => 'Utgången',
            Member::STATUS_DECEASED => 'Avliden',
            default                 => $status,
        };
    }

    public function statusClasses(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            Member::STATUS_ACTIVE   => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            Member::STATUS_INACTIVE => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            Member::STATUS_EXPIRED  => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            Member::STATUS_DECEASED => 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400',
            default                 => 'bg-zinc-100 text-zinc-600',
        };
    }

    public function typeLabel(string $type): string
    {
        return match($type) {
            Member::TYPE_SINGLE   => 'Vuxen',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_CHILD    => 'Barn',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_HONORARY => 'Hedersmedlem',
            default               => $type,
        };
    }

    public function periodStatusLabel(string $status): string
    {
        return match($status) {
            'pending_payment' => 'Väntar på betalning',
            'active'          => 'Aktiv',
            'expired'         => 'Utgången',
            'cancelled'       => 'Avbruten',
            default           => $status,
        };
    }

    public function periodStatusClasses(string $status): string
    {
        return match($status) {
            'pending_payment' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            'active'          => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'expired'         => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            'cancelled'       => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            default           => 'bg-zinc-100 text-zinc-600',
        };
    }

    #[Computed]
    public function mailLogs()
    {
        return MailLog::where('member_id', $this->member->id)
            ->with('mailing')
            ->orderByDesc('sent_at')
            ->get();
    }

    // ── Google Workspace ───────────────────────────────────────────────────────

    public string $wsUsername       = '';
    public string $wsNewAlias       = '';
    public bool   $wsCreateForm     = false;
    public string $wsLinkEmail      = '';
    public bool   $wsLinkForm       = false;

    #[Computed]
    public function wsConfigured(): bool
    {
        return app(GoogleWorkspaceService::class)->isConfigured();
    }

    #[Computed]
    public function wsAliases(): array
    {
        $wsEmail = $this->member->workspaceAccount?->email;
        if (! $wsEmail) return [];
        return app(GoogleWorkspaceService::class)->getAliases($wsEmail) ?? [];
    }

    #[Computed]
    public function wsSuspended(): ?bool
    {
        $wsEmail = $this->member->workspaceAccount?->email;
        if (! $wsEmail) return null;
        return app(GoogleWorkspaceService::class)->isSuspended($wsEmail);
    }

    public function openWsCreateForm(): void
    {
        $ws = app(GoogleWorkspaceService::class);
        $suggested          = $ws->resolveEmail($this->member);
        $this->wsUsername   = explode('@', $suggested)[0];
        $this->wsCreateForm = true;
        $this->wsLinkForm   = false;
    }

    public function openWsLinkForm(): void
    {
        $this->wsLinkEmail  = '';
        $this->wsLinkForm   = true;
        $this->wsCreateForm = false;
    }

    public function linkWsAccount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);

        $domain = config('services.google_workspace.domain');
        $this->validate([
            'wsLinkEmail' => ['required', 'alpha_dash', "regex:/^[a-z0-9._-]+$/i"],
        ]);

        $email = $this->wsLinkEmail . '@' . $domain;
        $ws    = app(GoogleWorkspaceService::class);

        if ($ws->accountExists($email) === false) {
            $this->addError('wsLinkEmail', 'Inget Workspace-konto med den adressen hittades.');
            return;
        }

        $this->member->workspaceAccount()->updateOrCreate([], ['email' => $email]);
        $this->member->refresh();
        AuditLog::record('workspace_account_linked', 'members', $this->member->full_name . ' — ' . $email, $this->member);
        $this->wsLinkForm = false;
        unset($this->wsAliases, $this->wsSuspended);
    }

    public function createWsAccount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);

        $this->validate(['wsUsername' => 'required|alpha_dash']);

        $domain = config('services.google_workspace.domain');
        $email  = $this->wsUsername . '@' . $domain;
        $ws     = app(GoogleWorkspaceService::class);

        if ($ws->accountExists($email) === true) {
            $this->addError('wsUsername', 'Det finns redan ett konto med den adressen.');
            return;
        }

        $created = $ws->createAccountWithEmail($this->member, $email);

        if ($created) {
            $this->member->refresh();
            AuditLog::record('workspace_account_created', 'members', $this->member->full_name . ' — ' . $email, $this->member);
            $this->wsCreateForm = false;
            unset($this->wsAliases, $this->wsSuspended);
        } else {
            $this->addError('wsUsername', 'Kunde inte skapa kontot. Kontrollera loggar.');
        }
    }

    public function addWsAlias(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);

        $this->validate(['wsNewAlias' => 'required|alpha_dash']);

        $domain = config('services.google_workspace.domain');

        $wsEmail = $this->member->workspaceAccount?->email;
        $ok = app(GoogleWorkspaceService::class)->addAlias(
            $wsEmail,
            $this->wsNewAlias . '@' . $domain
        );

        if ($ok) {
            $addedAlias = $this->wsNewAlias . '@' . config('services.google_workspace.domain');
            AuditLog::record('workspace_alias_added', 'members', $wsEmail . ' ← ' . $addedAlias, $this->member);
            $this->wsNewAlias = '';
            unset($this->wsAliases);
        } else {
            $this->addError('wsNewAlias', 'Kunde inte lägga till alias. Det kanske redan finns.');
        }
    }

    public function removeWsAlias(string $alias): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);

        $wsEmail = $this->member->workspaceAccount?->email;
        app(GoogleWorkspaceService::class)->removeAlias($wsEmail, $alias);
        AuditLog::record('workspace_alias_removed', 'members', $wsEmail . ' — ' . $alias, $this->member);
        unset($this->wsAliases);
    }

    public function suspendWsAccount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);

        $wsEmail = $this->member->workspaceAccount?->email;
        app(GoogleWorkspaceService::class)->suspendAccount($wsEmail);
        AuditLog::record('workspace_account_suspended', 'members', $wsEmail, $this->member);
        unset($this->wsSuspended);
    }

    public function restoreWsAccount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);

        $wsEmail = $this->member->workspaceAccount?->email;
        app(GoogleWorkspaceService::class)->restoreAccount($wsEmail);
        AuditLog::record('workspace_account_restored', 'members', $wsEmail, $this->member);
        unset($this->wsSuspended);
    }

    // ── Membrum invitation ─────────────────────────────────────────────────────

    public function sendInvitation(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);

        if ($this->member->user_id || ! $this->member->email) {
            return;
        }

        if (User::where('email', $this->member->email)->exists()) {
            $this->addError('invitation', 'En användare med den e-postadressen finns redan. Länka kontot manuellt.');
            return;
        }

        $user = User::create([
            'name'     => $this->member->full_name,
            'email'    => $this->member->email,
            'password' => Hash::make(Str::random(32)),
        ]);

        $this->member->update(['user_id' => $user->id]);
        $this->member->refresh()->load('user');

        /** @var \Illuminate\Auth\Passwords\PasswordBroker $broker */
        $broker        = Password::broker();
        $token         = $broker->createToken($user);
        $activationUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);

        Mail::to($user->email)->send(new MemberInvitationMail($this->member, $activationUrl));
        AuditLog::record('member_invitation_sent', 'members', $this->member->full_name . ' → ' . $user->email, $this->member);
    }
}; ?>


<div
    x-data="{
        tab: new URLSearchParams(window.location.search).get('tab') || 'medlemskap',
        setTab(t) {
            this.tab = t;
            history.replaceState(null, '', '?tab=' + t);
        }
    }"
>
    <div class="border-b border-zinc-200 bg-gradient-to-r from-brand/10 via-brand-orange/5 to-complement/10 py-5 dark:border-zinc-800 dark:from-brand/5 dark:via-brand-orange/5 dark:to-complement/5">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $member->full_name }}</h1>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Person</p>
                </div>
                <div class="flex shrink-0 gap-2">
                    @can('members.edit')
                    <a href="{{ route('admin.members.edit', $member) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                        Redigera
                    </a>
                    @endcan
                    <a href="{{ route('admin.members.index') }}" wire:navigate
                       class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                        ← Tillbaka
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">

        {{-- Person header --}}
        <div class="mb-8 flex items-center gap-4">
            @if($member->user?->avatar_path)
                <img src="{{ $member->user->avatarUrl(80) }}" alt="{{ $member->full_name }}"
                     class="size-14 rounded-full object-cover">
            @else
                <div class="flex size-14 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-lg font-bold text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300">
                    {{ mb_strtoupper(mb_substr($member->first_name, 0, 1)) }}
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $member->full_name }}</h1>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $this->statusClasses($member->status) }}">
                        {{ $this->statusLabel($member->status) }}
                    </span>
                    @if($member->membership_number)
                        <span class="text-sm text-zinc-400 dark:text-zinc-500">#{{ $member->membership_number }}</span>
                    @endif
                </div>
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $this->typeLabel($member->membership_type) }}
                    @if($member->user)
                        · <span class="text-green-600 dark:text-green-400">Har konto</span>
                    @else
                        · <span class="text-zinc-400">Inget konto</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="mb-8 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-6">
                <button
                    @click="setTab('medlemskap')"
                    :class="tab === 'medlemskap'
                        ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold'
                        : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors"
                >
                    <x-bootstrap-icon name="person-badge" class="size-4" />
                    Medlemskap
                </button>
                <button
                    @click="setTab('konto')"
                    :class="tab === 'konto'
                        ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold'
                        : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors"
                >
                    <x-bootstrap-icon name="person-gear" class="size-4" />
                    Konto
                </button>
                @if($member->family_group_id)
                <button
                    @click="setTab('familj')"
                    :class="tab === 'familj'
                        ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold'
                        : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors"
                >
                    <x-bootstrap-icon name="house-heart" class="size-4" />
                    Familj
                </button>
                @endif
                <button
                    @click="setTab('utskick')"
                    :class="tab === 'utskick'
                        ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold'
                        : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors"
                >
                    <x-bootstrap-icon name="envelope" class="size-4" />
                    Utskick
                    @if($this->mailLogs->isNotEmpty())
                        <span class="ml-0.5 rounded-full bg-zinc-200 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                            {{ $this->mailLogs->count() }}
                        </span>
                    @endif
                </button>
            </nav>
        </div>

        {{-- ── TAB: Medlemskap ──────────────────────────────────────────────────── --}}
        <div x-show="tab === 'medlemskap'" x-cloak>
            <div class="grid gap-12 lg:grid-cols-3">

                <div class="lg:col-span-2 space-y-10">

                    {{-- Personuppgifter --}}
                    <section>
                        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Personuppgifter</h2>
                        <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <div class="flex justify-between py-2.5">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Namn</dt>
                                <dd class="text-sm font-medium text-zinc-900 dark:text-white">{{ $member->full_name }}</dd>
                            </div>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">E-post</dt>
                                <dd class="text-sm text-zinc-900 dark:text-white">
                                    <a href="mailto:{{ $member->email }}" class="text-complement hover:underline dark:text-complement-light">{{ $member->email }}</a>
                                </dd>
                            </div>
                            @if($member->phone)
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Telefon</dt>
                                    <dd class="text-sm text-zinc-900 dark:text-white">
                                        <a href="tel:{{ $member->phone }}" class="text-complement hover:underline dark:text-complement-light">{{ $member->phone }}</a>
                                    </dd>
                                </div>
                            @endif
                            @if($member->address)
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Adress</dt>
                                    <dd class="text-right text-sm text-zinc-900 dark:text-white">
                                        {{ $member->address }}<br>
                                        {{ $member->postal_code }} {{ $member->city }}
                                    </dd>
                                </div>
                            @endif
                            @if($member->date_of_birth)
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Född</dt>
                                    <dd class="text-sm text-zinc-900 dark:text-white">
                                        {{ $member->date_of_birth->format('Y-m-d') }}
                                        <span class="text-zinc-400 dark:text-zinc-500">({{ $member->date_of_birth->age }} år)</span>
                                    </dd>
                                </div>
                            @endif
                            @if($member->notes)
                                <div class="py-2.5">
                                    <dt class="mb-1 text-sm text-zinc-500 dark:text-zinc-400">Anteckningar</dt>
                                    <dd class="text-sm text-zinc-900 dark:text-white whitespace-pre-line">{{ $member->notes }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>

                    {{-- Medlemskapsperioder --}}
                    <section>
                        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Perioder</h2>
                        @if($member->membershipPeriods->isEmpty())
                            <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga perioder registrerade.</p>
                        @else
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">År</th>
                                        <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Typ</th>
                                        <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Status</th>
                                        <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Aktiverat</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach($member->membershipPeriods as $period)
                                        <tr>
                                            <td class="py-2.5 font-medium text-zinc-900 dark:text-white">{{ $period->year }}</td>
                                            <td class="py-2.5 text-zinc-600 dark:text-zinc-400">{{ $this->typeLabel($period->membership_type) }}</td>
                                            <td class="py-2.5">
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->periodStatusClasses($period->status) }}">
                                                    {{ $this->periodStatusLabel($period->status) }}
                                                </span>
                                            </td>
                                            <td class="py-2.5 text-zinc-500 dark:text-zinc-400">
                                                {{ $period->activated_at?->format('d/m Y') ?? '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>

                    {{-- Sektioner --}}
                    @if($member->sections->isNotEmpty())
                        <section>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Grupper</h2>
                            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($member->sections as $section)
                                    <li class="flex items-center justify-between py-2.5">
                                        <span class="text-sm text-zinc-900 dark:text-white">{{ $section->name }}</span>
                                        @php $role = $member->sectionRoles->firstWhere('section_id', $section->id); @endphp
                                        @if($role)
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $role->title }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                </div>

                {{-- Sidebar --}}
                <div class="space-y-8">

                    <section>
                        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Detaljer</h2>
                        <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <div class="flex justify-between py-2.5">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Typ</dt>
                                <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->typeLabel($member->membership_type) }}</dd>
                            </div>
                            @if($member->membership_number)
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Medlemsnr</dt>
                                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">{{ $member->membership_number }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between py-2.5">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Skapad</dt>
                                <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member->created_at->format('d/m Y') }}</dd>
                            </div>
                            <div class="flex justify-between py-2.5">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Uppdaterad</dt>
                                <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member->updated_at->format('d/m Y') }}</dd>
                            </div>
                        </dl>
                    </section>

                    @if($member->organizationalRoleMemberships->isNotEmpty())
                        <section>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Organisationsroller</h2>
                            <ul class="space-y-3">
                                @foreach($member->organizationalRoleMemberships as $orm)
                                    <li>
                                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $orm->title }}</p>
                                        <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $orm->period->organizationalRole->name }} — {{ $orm->period->name }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif


                </div>
            </div>
        </div>

        {{-- ── TAB: Konto ───────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'konto'" x-cloak>
            @if($member->user)
                @php $user = $member->user; @endphp
                <div class="grid gap-12 lg:grid-cols-3">

                    <div class="lg:col-span-2 space-y-10">

                        <section>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Konto</h2>
                            <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Namn</dt>
                                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }}</dd>
                                </div>
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">E-post</dt>
                                    <dd class="text-sm">
                                        <a href="mailto:{{ $user->email }}" class="text-complement hover:underline dark:text-complement-light">{{ $user->email }}</a>
                                    </dd>
                                </div>
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Lösenord</dt>
                                    <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->password ? 'Satt' : 'Ej satt' }}</dd>
                                </div>
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">2FA</dt>
                                    <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->two_factor_secret ? 'Aktiverad' : 'Ej aktiverad' }}</dd>
                                </div>
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Tema</dt>
                                    <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ match($user->ui_theme) { 'light' => 'Ljust', 'dark' => 'Mörkt', default => 'System' } }}</dd>
                                </div>
                                <div class="flex justify-between py-2.5">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Registrerad</dt>
                                    <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->created_at->format('d/m Y') }}</dd>
                                </div>
                            </dl>
                        </section>

                        @if($this->wsConfigured)
                            <section>
                                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Google Workspace</h2>

                                @php $wsEmail = $member->workspaceAccount?->email; @endphp
                                @if($wsEmail)
                                    <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        <div class="flex items-center justify-between py-2.5">
                                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Konto</dt>
                                            <dd class="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                                                {{ $wsEmail }}
                                                @if($this->wsSuspended)
                                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">Avstängt</span>
                                                @endif
                                            </dd>
                                        </div>

                                        @if(count($this->wsAliases))
                                            <div class="py-2.5">
                                                <dt class="mb-1.5 text-sm text-zinc-500 dark:text-zinc-400">Alias</dt>
                                                <dd class="space-y-1">
                                                    @foreach($this->wsAliases as $alias)
                                                        <div class="flex items-center justify-between">
                                                            <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ $alias }}</span>
                                                            @can('members.manage')
                                                                <button wire:click="removeWsAlias('{{ $alias }}')"
                                                                        wire:confirm="Ta bort alias {{ $alias }}?"
                                                                        class="text-xs text-red-500 hover:text-red-700 dark:text-red-400">Ta bort</button>
                                                            @endcan
                                                        </div>
                                                    @endforeach
                                                </dd>
                                            </div>
                                        @endif
                                    </dl>

                                    @can('members.manage')
                                        <div class="mt-4 space-y-3">
                                            {{-- Add alias --}}
                                            <div class="flex items-center gap-2">
                                                <div class="flex flex-1 overflow-hidden rounded border border-zinc-300 focus-within:ring-2 focus-within:ring-brand-saffron dark:border-zinc-600">
                                                    <input wire:model="wsNewAlias" type="text"
                                                           placeholder="smeknamn"
                                                           class="min-w-0 flex-1 bg-white px-2 py-1.5 text-sm text-zinc-900 outline-none dark:bg-zinc-800 dark:text-zinc-100" />
                                                    <span class="flex items-center border-l border-zinc-300 bg-zinc-50 px-2 text-sm text-zinc-400 select-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-500">@{{ config('services.google_workspace.domain') }}</span>
                                                </div>
                                                <button wire:click="addWsAlias" class="btn btn-secondary btn-sm whitespace-nowrap">Lägg till</button>
                                            </div>
                                            @error('wsNewAlias') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

                                            {{-- Suspend / restore --}}
                                            @if($this->wsSuspended)
                                                <button wire:click="restoreWsAccount"
                                                        wire:confirm="Återaktivera {{ $wsEmail }}?"
                                                        class="btn btn-sm bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-300">
                                                    Återaktivera konto
                                                </button>
                                            @else
                                                <button wire:click="suspendWsAccount"
                                                        wire:confirm="Stäng av {{ $wsEmail }}? Personen kan inte logga in tills kontot återaktiveras."
                                                        class="btn btn-sm btn-danger">
                                                    Stäng av konto
                                                </button>
                                            @endif
                                        </div>
                                    @endcan

                                @else
                                    @can('members.manage')
                                        @if(! $wsCreateForm && ! $wsLinkForm)
                                            <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">Ingen @armagedon.se-adress kopplad.</p>
                                            <div class="flex gap-2">
                                                <button wire:click="openWsCreateForm" class="btn btn-secondary btn-sm">
                                                    Skapa Workspace-konto
                                                </button>
                                                <button wire:click="openWsLinkForm" class="btn btn-ghost btn-sm">
                                                    Koppla befintligt
                                                </button>
                                            </div>
                                        @elseif($wsCreateForm)
                                            <div class="space-y-3">
                                                <div>
                                                    <label class="lbl text-xs" for="wsUsername">Kontoadress</label>
                                                    <div class="mt-1 flex overflow-hidden rounded border border-zinc-300 focus-within:ring-2 focus-within:ring-brand-saffron dark:border-zinc-600">
                                                        <input id="wsUsername" wire:model="wsUsername" type="text"
                                                               class="min-w-0 flex-1 bg-white px-2 py-1.5 font-mono text-sm text-zinc-900 outline-none dark:bg-zinc-800 dark:text-zinc-100" />
                                                        <span class="flex items-center border-l border-zinc-300 bg-zinc-50 px-2 font-mono text-sm text-zinc-400 select-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-500">@{{ config('services.google_workspace.domain') }}</span>
                                                    </div>
                                                    @error('wsUsername') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                                        Medlemmen får ett lösenordsåterställnings-mail vid första inloggning.
                                                    </p>
                                                </div>
                                                <div class="flex gap-2">
                                                    <button wire:click="createWsAccount" class="btn btn-primary btn-sm">Skapa konto</button>
                                                    <button wire:click="$set('wsCreateForm', false)" class="btn btn-ghost btn-sm">Avbryt</button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="space-y-3">
                                                <div>
                                                    <label class="lbl text-xs" for="wsLinkEmail">Befintlig kontoadress</label>
                                                    <div class="mt-1 flex overflow-hidden rounded border border-zinc-300 focus-within:ring-2 focus-within:ring-brand-saffron dark:border-zinc-600">
                                                        <input id="wsLinkEmail" wire:model="wsLinkEmail" type="text"
                                                               class="min-w-0 flex-1 bg-white px-2 py-1.5 font-mono text-sm text-zinc-900 outline-none dark:bg-zinc-800 dark:text-zinc-100"
                                                               placeholder="fornamn.efternamn" />
                                                        <span class="flex items-center border-l border-zinc-300 bg-zinc-50 px-2 font-mono text-sm text-zinc-400 select-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-500">@{{ config('services.google_workspace.domain') }}</span>
                                                    </div>
                                                    @error('wsLinkEmail') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                                        Kontot måste redan finnas i Google Workspace.
                                                    </p>
                                                </div>
                                                <div class="flex gap-2">
                                                    <button wire:click="linkWsAccount" class="btn btn-primary btn-sm">Koppla konto</button>
                                                    <button wire:click="$set('wsLinkForm', false)" class="btn btn-ghost btn-sm">Avbryt</button>
                                                </div>
                                            </div>
                                        @endif
                                    @endcan
                                @endif
                            </section>
                        @endif

                        @if($user->cogworks_id || $user->cogworks_username)
                            <section>
                                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">CogWorks</h2>
                                <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @if($user->cogworks_id)
                                        <div class="flex justify-between py-2.5">
                                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">ID</dt>
                                            <dd class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $user->cogworks_id }}</dd>
                                        </div>
                                    @endif
                                    @if($user->cogworks_username)
                                        <div class="flex justify-between py-2.5">
                                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Användarnamn</dt>
                                            <dd class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ $user->cogworks_username }}</dd>
                                        </div>
                                    @endif
                                    @if($user->cogworks_synced_at)
                                        <div class="flex justify-between py-2.5">
                                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Senast synkad</dt>
                                            <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->cogworks_synced_at->format('d/m Y H:i') }}</dd>
                                        </div>
                                    @endif
                                </dl>
                            </section>
                        @endif

                    </div>

                    {{-- Sidebar --}}
                    <div class="space-y-8">

                        <section>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Profilbild</h2>
                            <img src="{{ $user->avatarUrl(120) }}" alt="{{ $user->name }}"
                                 class="size-20 rounded-full {{ $user->avatar_path ? 'object-cover' : '' }}">
                            <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">{{ $user->avatar_path ? 'Anpassad bild' : 'Gravatar' }}</p>
                        </section>

                        <section>
                            <div class="mb-3 flex items-center gap-1.5">
                                <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Roller</h2>
                                <x-permission-hint permission="users.manage-roles" />
                            </div>
                            @can('users.manage-roles')
                                <div class="space-y-1">
                                    @foreach($this->allRoles as $role)
                                        <label class="flex cursor-pointer items-center gap-2.5">
                                            <input type="checkbox"
                                                   wire:model="assignedRoles"
                                                   value="{{ $role->name }}"
                                                   class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                                {{ $role->label ?: $role->name }}
                                                @if($role->label)
                                                    <span class="font-mono text-xs text-zinc-400 dark:text-zinc-500">({{ $role->name }})</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('assignedRoles')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                                <button wire:click="saveRoles" wire:loading.attr="disabled"
                                        class="btn btn-primary btn-sm mt-3">
                                    <span wire:loading.remove wire:target="saveRoles">Spara roller</span>
                                    <span wire:loading wire:target="saveRoles">Sparar…</span>
                                </button>
                            @else
                                @php $roles = $user->roles; @endphp
                                @if($roles->isEmpty())
                                    <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga roller.</p>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($roles as $role)
                                            <span class="inline-flex items-center rounded-full bg-complement/10 px-2.5 py-0.5 text-xs font-medium text-complement dark:bg-complement/20 dark:text-complement-light">
                                                {{ $role->label ?: $role->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            @endcan
                        </section>

                    </div>
                </div>
            @else
                <div class="py-12 text-center">
                    <x-bootstrap-icon name="person-x" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Inget användarkonto kopplat till denna person.</p>

                    @can('members.manage')
                        @if($member->email)
                            @php $emailTaken = \App\Models\User::where('email', $member->email)->exists(); @endphp
                            @if($emailTaken)
                                <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                    E-postadressen <strong>{{ $member->email }}</strong> används redan av ett annat konto. Länka kontot manuellt via Redigera-sidan.
                                </p>
                            @else
                                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                    Skapa ett konto och skicka inbjudan till <strong>{{ $member->email }}</strong>.
                                </p>
                                @error('invitation')
                                    <p class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                                <button wire:click="sendInvitation"
                                        wire:confirm="Skapa konto och skicka inbjudan till {{ $member->email }}?"
                                        class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                                    <x-bootstrap-icon name="envelope-plus" class="size-4" />
                                    Skicka inbjudan
                                </button>
                            @endif
                        @else
                            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                Ange en e-postadress på medlemskortet för att kunna skicka en inbjudan.
                            </p>
                            <a href="{{ route('admin.members.edit', $member) }}" wire:navigate
                               class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                Lägg till e-postadress
                            </a>
                        @endif
                    @endcan
                </div>
            @endif
        </div>

        {{-- ── TAB: Familj ──────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'familj'" x-cloak>
            @if($member->familyGroup)
                @php
                    $fg = $member->familyGroup;
                    $memberPivot = \Illuminate\Support\Facades\DB::table('family_members')
                        ->where('family_group_id', $fg->id)
                        ->where('member_id', $member->id)
                        ->first();
                    $isPending = $memberPivot?->status === \App\Models\Membership\FamilyGroup::STATUS_PENDING;
                @endphp

                <div class="mb-4 flex items-center justify-between">
                    @can('members.manage')
                    <a href="{{ route('admin.family-groups.index') }}" wire:navigate
                       class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                        <x-bootstrap-icon name="box-arrow-up-right" class="size-3.5" />
                        Hantera alla familjegrupper
                    </a>
                    @endcan
                </div>

                @if($isPending)
                    <div class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/10 dark:text-amber-300">
                        <x-bootstrap-icon name="clock" class="mt-0.5 size-4 shrink-0" />
                        <span>Inbjudan väntande — medlemmen har ännu inte accepterat inbjudan till familjegruppen.</span>
                    </div>
                @endif

                <div class="space-y-6">
                    {{-- Active members --}}
                    @if($fg->membersWithPivot->isNotEmpty())
                        <section>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                Aktiva familjemedlemmar ({{ $fg->membersWithPivot->count() }})
                            </h2>
                            <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                                @foreach($fg->membersWithPivot as $fm)
                                    <li class="flex items-center gap-3 px-4 py-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <a href="{{ route('admin.members.show', $fm) }}" wire:navigate
                                                   class="text-sm font-medium {{ $fm->id === $member->id ? 'text-complement dark:text-complement-light' : 'text-zinc-900 dark:text-white hover:text-complement dark:hover:text-complement-light' }}">
                                                    {{ $fm->full_name }}
                                                </a>
                                                @if($fm->pivot->role === \App\Models\Membership\FamilyGroup::ROLE_CHILD)
                                                    <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Barn</span>
                                                @else
                                                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Vuxen</span>
                                                @endif
                                                @if($fg->administrator_member_id === $fm->id)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-complement/10 px-2 py-0.5 text-xs font-medium text-complement dark:text-complement-light">
                                                        <x-bootstrap-icon name="receipt" class="size-3" />
                                                        Betalningsansvarig
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    {{-- Pending invitations --}}
                    @if($fg->pendingInvitations->isNotEmpty())
                        <section>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                Väntande inbjudningar ({{ $fg->pendingInvitations->count() }})
                            </h2>
                            <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-amber-200 dark:divide-zinc-800 dark:border-amber-800/50">
                                @foreach($fg->pendingInvitations as $inv)
                                    <li class="flex items-center gap-3 bg-amber-50 px-4 py-3 dark:bg-amber-900/5">
                                        <a href="{{ route('admin.members.show', $inv) }}" wire:navigate
                                           class="text-sm font-medium {{ $inv->id === $member->id ? 'text-complement dark:text-complement-light' : 'text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light' }}">
                                            {{ $inv->full_name }}
                                        </a>
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                            <x-bootstrap-icon name="clock" class="size-3" />
                                            Väntar på svar
                                        </span>
                                        @if($inv->pivot->invited_at)
                                            <span class="ml-auto text-xs text-zinc-400">{{ $inv->pivot->invited_at->translatedFormat('j M Y') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>
            @else
                <div class="py-16 text-center text-sm text-zinc-400 dark:text-zinc-500">
                    Tillhör ingen familjegrupp.
                </div>
            @endif
        </div>

        {{-- ── TAB: Utskick ─────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'utskick'" x-cloak>
            @if($this->mailLogs->isEmpty())
                <div class="py-16 text-center">
                    <x-bootstrap-icon name="envelope-x" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Inga skickade meddelanden ännu.</p>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Ämne</th>
                                <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 md:table-cell">Typ</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Skickat</th>
                                <th class="px-5 py-3 text-center text-xs font-semibold uppercase tracking-wider text-zinc-400">Öppnat</th>
                                <th class="hidden px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 lg:table-cell">Öppningar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($this->mailLogs as $log)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ $log->subject }}</p>
                                        @if($log->mailing)
                                            <p class="text-xs text-zinc-400">{{ $log->mailing->title }}</p>
                                        @endif
                                    </td>
                                    <td class="hidden px-5 py-3 md:table-cell">
                                        <span class="inline-flex rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ $log->typeLabel() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-zinc-500 dark:text-zinc-400">
                                        {{ $log->sent_at->format('d/m Y H:i') }}
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        @if($log->isFailed())
                                            <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                <x-bootstrap-icon name="x-circle" class="size-3" /> Misslyckades
                                            </span>
                                        @elseif($log->wasOpened())
                                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                <x-bootstrap-icon name="eye" class="size-3" /> Ja
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                                <x-bootstrap-icon name="dash" class="size-3" /> Nej
                                            </span>
                                        @endif
                                    </td>
                                    <td class="hidden px-5 py-3 text-right text-zinc-500 dark:text-zinc-400 lg:table-cell">
                                        @if($log->wasOpened())
                                            {{ $log->open_count }}×
                                            <span class="ml-1 text-xs text-zinc-400">({{ $log->first_opened_at->format('d/m Y H:i') }})</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    </div>
</div>
