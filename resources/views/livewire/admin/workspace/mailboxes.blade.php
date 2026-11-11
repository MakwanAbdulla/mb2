<?php

use App\Models\AuditLog;
use App\Models\InboxMailbox;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Inkorgar');

new class extends Component {

    // List state
    public ?int $editId = null;

    // Form fields
    public string  $name           = '';
    public string  $purpose        = '';
    public string  $ownerType      = '';   // 'section' | 'org_role' | ''
    public ?int    $ownerId        = null;
    public string  $driver         = InboxMailbox::DRIVER_GOOGLE;
    public string  $mailbox        = '';
    public string  $imapHost       = '';
    public int     $imapPort       = 993;
    public string  $imapEncryption = 'ssl';
    public string  $imapUsername   = '';
    public string  $imapPassword   = '';
    public string  $imapFolder     = 'INBOX';
    public string  $smtpHost       = '';
    public int     $smtpPort       = 587;
    public string  $smtpUsername   = '';
    public string  $smtpPassword   = '';
    public bool    $isEnabled      = true;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('workspace.manage'), 403);
    }

    public function mailboxes()
    {
        return InboxMailbox::orderBy('purpose')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->editId = 0; // 0 = new
    }

    public function edit(int $id): void
    {
        $mb = InboxMailbox::findOrFail($id);

        $this->editId     = $id;
        $this->name       = $mb->name;
        $this->purpose    = $mb->purpose ?? '';
        $this->ownerType  = match ($mb->owner_type) {
            Section::class             => 'section',
            OrganizationalRole::class  => 'org_role',
            default                    => '',
        };
        $this->ownerId    = $mb->owner_id;
        $this->driver     = $mb->driver;
        $this->mailbox        = $mb->mailbox ?? '';
        $this->imapHost       = $mb->imap_host ?? '';
        $this->imapPort       = $mb->imap_port;
        $this->imapEncryption = $mb->imap_encryption;
        $this->imapUsername   = $mb->imap_username ?? '';
        $this->imapPassword   = ''; // never pre-fill passwords
        $this->imapFolder     = $mb->imap_folder;
        $this->smtpHost       = $mb->smtp_host ?? '';
        $this->smtpPort       = $mb->smtp_port;
        $this->smtpUsername   = $mb->smtp_username ?? '';
        $this->smtpPassword   = '';
        $this->isEnabled      = $mb->is_enabled;
    }

    public function save(): void
    {
        $this->validate([
            'name'      => 'required|string|max:100',
            'purpose'   => 'nullable|string|max:50',
            'ownerType' => 'nullable|in:section,org_role',
            'ownerId'   => 'nullable|integer',
            'driver'    => 'required|in:google_workspace,imap',
            'mailbox'  => 'nullable|email|max:255',
            'imapHost' => 'nullable|string|max:255',
            'imapPort' => 'integer|min:1|max:65535',
            'imapEncryption' => 'in:ssl,tls,starttls,none',
            'imapUsername'   => 'nullable|string|max:255',
            'imapFolder'     => 'required|string|max:100',
            'smtpHost' => 'nullable|string|max:255',
            'smtpPort' => 'integer|min:1|max:65535',
            'smtpUsername'   => 'nullable|string|max:255',
        ]);

        $ownerType = match ($this->ownerType) {
            'section'  => Section::class,
            'org_role' => OrganizationalRole::class,
            default    => null,
        };

        $data = [
            'name'       => $this->name,
            'purpose'    => $this->purpose ?: null,
            'owner_type' => $ownerType,
            'owner_id'   => $ownerType ? $this->ownerId : null,
            'driver'     => $this->driver,
            'mailbox'        => $this->mailbox ?: null,
            'imap_host'      => $this->imapHost ?: null,
            'imap_port'      => $this->imapPort,
            'imap_encryption'=> $this->imapEncryption,
            'imap_username'  => $this->imapUsername ?: null,
            'imap_folder'    => $this->imapFolder,
            'smtp_host'      => $this->smtpHost ?: null,
            'smtp_port'      => $this->smtpPort,
            'smtp_username'  => $this->smtpUsername ?: null,
            'is_enabled'     => $this->isEnabled,
        ];

        if ($this->editId === 0) {
            $mb = InboxMailbox::create($data);
            AuditLog::record('mailbox_created', 'workspace', $mb->name, $mb);
        } else {
            $mb = InboxMailbox::findOrFail($this->editId);
            $mb->update($data);
            AuditLog::record('mailbox_updated', 'workspace', $mb->name, $mb);
        }

        // Only update passwords if a new value was entered
        if ($this->imapPassword !== '') {
            $mb->update(['imap_password' => $this->imapPassword]);
        }
        if ($this->smtpPassword !== '') {
            $mb->update(['smtp_password' => $this->smtpPassword]);
        }

        $this->editId = null;
    }

    public function delete(int $id): void
    {
        $mb = InboxMailbox::findOrFail($id);
        AuditLog::record('mailbox_deleted', 'workspace', $mb->name, null);
        $mb->delete();
    }

    public function cancel(): void
    {
        $this->editId = null;
    }

    private function resetForm(): void
    {
        $this->name      = '';
        $this->purpose   = '';
        $this->ownerType = '';
        $this->ownerId   = null;
        $this->driver    = InboxMailbox::DRIVER_GOOGLE;
        $this->mailbox        = '';
        $this->imapHost       = '';
        $this->imapPort       = 993;
        $this->imapEncryption = 'ssl';
        $this->imapUsername   = '';
        $this->imapPassword   = '';
        $this->imapFolder     = 'INBOX';
        $this->smtpHost       = '';
        $this->smtpPort       = 587;
        $this->smtpUsername   = '';
        $this->smtpPassword   = '';
        $this->isEnabled      = true;
    }
}; ?>

<div>
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">

        <div class="mb-4 flex justify-end">
            <button wire:click="create" class="btn btn-primary btn-sm">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Ny inkorg
            </button>
        </div>

        {{-- List --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            @forelse($this->mailboxes() as $mb)
                <div class="flex items-center gap-4 border-b border-zinc-100 bg-white px-5 py-3.5 last:border-0 dark:border-zinc-800 dark:bg-zinc-900 {{ !$mb->is_enabled ? 'opacity-50' : '' }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $mb->name }}</span>
                            @if($mb->purpose)
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $mb->purpose }}</span>
                            @endif
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $mb->driver === 'google_workspace' ? 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400' : 'bg-amber-50 text-amber-600 dark:bg-amber-900/20 dark:text-amber-400' }}">
                                {{ $mb->driver === 'google_workspace' ? 'Google' : 'IMAP' }}
                            </span>
                        </div>
                        <div class="mt-0.5 font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $mb->address() }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if($mb->driver === 'google_workspace' && $mb->address())
                            <x-gmail-link :email="$mb->address()" size="xs" />
                        @endif
                        <button wire:click="edit({{ $mb->id }})" class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Redigera</button>
                        <button wire:click="delete({{ $mb->id }})"
                                wire:confirm="Ta bort inkorg {{ $mb->name }}?"
                                class="text-xs text-red-500 hover:text-red-700">Ta bort</button>
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm text-zinc-400 dark:text-zinc-500">
                    Inga inkorgar konfigurerade ännu.
                </div>
            @endforelse
        </div>

    </div>

    {{-- Edit/Create slide-in --}}
    @if($editId !== null)
        <div class="fixed inset-0 z-40 flex justify-end">
            <div class="absolute inset-0 bg-black/30 dark:bg-black/50" wire:click="cancel"></div>
            <div class="relative z-50 w-full max-w-md overflow-y-auto bg-white shadow-xl dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">
                        {{ $editId === 0 ? 'Ny inkorg' : 'Redigera inkorg' }}
                    </h2>
                </div>

                <div class="space-y-4 px-6 py-5">

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="lbl">Namn</label>
                            <input wire:model="name" type="text" class="inp mt-1" placeholder="Kansliinkorg" />
                            @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lbl">Syfte</label>
                            <select wire:model="purpose" class="inp mt-1">
                                <option value="">— Anpassat —</option>
                                <option value="office">office (kansli)</option>
                                <option value="accounting">accounting (bokföring)</option>
                            </select>
                        </div>
                        <div>
                            <label class="lbl">Drivrutin</label>
                            <select wire:model.live="driver" class="inp mt-1">
                                <option value="google_workspace">Google Workspace</option>
                                <option value="imap">IMAP</option>
                            </select>
                        </div>
                        <div>
                            <label class="lbl">Ägare (typ)</label>
                            <select wire:model.live="ownerType" class="inp mt-1">
                                <option value="">— Inget —</option>
                                <option value="section">Sektion</option>
                                <option value="org_role">Org-roll</option>
                            </select>
                        </div>
                        @if($ownerType === 'section')
                        <div>
                            <label class="lbl">Sektion</label>
                            <select wire:model.number="ownerId" class="inp mt-1">
                                <option value="">— Välj —</option>
                                @foreach(\App\Models\Organization\Section::orderBy('name')->get() as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @elseif($ownerType === 'org_role')
                        <div>
                            <label class="lbl">Org-roll</label>
                            <select wire:model.number="ownerId" class="inp mt-1">
                                <option value="">— Välj —</option>
                                @foreach(\App\Models\Organization\OrganizationalRole::orderBy('name')->get() as $r)
                                    <option value="{{ $r->id }}">{{ $r->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                    </div>

                    @if($driver === 'google_workspace')
                        <div>
                            <label class="lbl">Gmail-adress (DWD)</label>
                            <input wire:model="mailbox" type="email" class="inp mt-1" placeholder="kansli@armagedon.se" />
                            @error('mailbox') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">IMAP</p>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <label class="lbl">Host</label>
                                <input wire:model="imapHost" type="text" class="inp mt-1" placeholder="mail.example.com" />
                            </div>
                            <div>
                                <label class="lbl">Port</label>
                                <input wire:model="imapPort" type="number" class="inp mt-1" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="lbl">Kryptering</label>
                                <select wire:model="imapEncryption" class="inp mt-1">
                                    <option value="ssl">SSL</option>
                                    <option value="tls">TLS</option>
                                    <option value="starttls">STARTTLS</option>
                                    <option value="none">Ingen</option>
                                </select>
                            </div>
                            <div>
                                <label class="lbl">Mapp</label>
                                <input wire:model="imapFolder" type="text" class="inp mt-1" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="lbl">Användarnamn</label>
                                <input wire:model="imapUsername" type="text" class="inp mt-1" />
                            </div>
                            <div>
                                <label class="lbl">Lösenord {{ $editId > 0 ? '(lämna tomt = behåll)' : '' }}</label>
                                <input wire:model="imapPassword" type="password" class="inp mt-1" autocomplete="new-password" />
                            </div>
                        </div>

                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">SMTP (för svar)</p>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <label class="lbl">SMTP Host <span class="font-normal text-zinc-400">(lämna tomt = systemmailer)</span></label>
                                <input wire:model="smtpHost" type="text" class="inp mt-1" placeholder="smtp.example.com" />
                            </div>
                            <div>
                                <label class="lbl">Port</label>
                                <input wire:model="smtpPort" type="number" class="inp mt-1" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="lbl">Användarnamn</label>
                                <input wire:model="smtpUsername" type="text" class="inp mt-1" />
                            </div>
                            <div>
                                <label class="lbl">Lösenord {{ $editId > 0 ? '(lämna tomt = behåll)' : '' }}</label>
                                <input wire:model="smtpPassword" type="password" class="inp mt-1" autocomplete="new-password" />
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center gap-2 pt-1">
                        <input wire:model="isEnabled" type="checkbox" id="isEnabled" class="size-4 rounded" />
                        <label for="isEnabled" class="lbl mb-0">Aktiv</label>
                    </div>

                </div>

                <div class="flex gap-3 border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <button wire:click="save" class="btn btn-primary">Spara</button>
                    <button wire:click="cancel" class="btn btn-ghost">Avbryt</button>
                </div>
            </div>
        </div>
    @endif
</div>
