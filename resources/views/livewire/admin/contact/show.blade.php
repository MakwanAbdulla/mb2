<?php

use App\Models\AuditLog;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kontaktmeddelande');

new class extends Component {

    public ContactMessage $contactMessage;
    public string $notes = '';

    public function mount(ContactMessage $contactMessage): void
    {
        abort_unless(Auth::user()->canAny(['members.manage', 'admin']), 403);

        $this->contactMessage = $contactMessage;
        $this->notes          = $contactMessage->notes ?? '';

        $contactMessage->markRead();
    }

    public function saveNotes(): void
    {
        $this->contactMessage->update(['notes' => $this->notes ?: null]);
        AuditLog::record('contact_notes_saved', 'cms', $this->contactMessage->subject, $this->contactMessage);
        $this->dispatch('notify', message: 'Anteckningar sparade.');
    }

    public function markReplied(): void
    {
        $this->contactMessage->markReplied(Auth::id());
        AuditLog::record('contact_marked_replied', 'cms', $this->contactMessage->subject, $this->contactMessage);
        $this->contactMessage->refresh();
    }

    public function markSpam(): void
    {
        $this->contactMessage->markSpam();
        AuditLog::record('contact_marked_spam', 'cms', $this->contactMessage->subject, null);
        $this->redirect(route('admin.contact.index'), navigate: true);
    }

    public function delete(): void
    {
        $subject = $this->contactMessage->subject;
        $this->contactMessage->delete();
        AuditLog::record('contact_deleted', 'cms', $subject, null);
        $this->redirect(route('admin.contact.index'), navigate: true);
    }
};
?>

<div>
    <x-slot:header>
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Kontaktmeddelande</h1>
            <a href="{{ route('admin.contact.index') }}" wire:navigate class="btn btn-ghost btn-sm">
                <x-bootstrap-icon name="arrow-left" class="size-4" /> Tillbaka
            </a>
        </div>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-[1fr_260px]">

        <div class="space-y-5">

            {{-- Message --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $contactMessage->subject }}</h2>
                        <p class="text-sm text-zinc-400 mt-0.5">{{ $contactMessage->created_at->format('d/m Y H:i') }}</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ match($contactMessage->status) {
                            'new'     => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
                            'read'    => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                            'replied' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                            'spam'    => 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500',
                            default   => 'bg-zinc-100 text-zinc-500',
                        } }}">
                        {{ $contactMessage->statusLabel() }}
                    </span>
                </div>

                <div class="prose prose-zinc max-w-none dark:prose-invert text-sm whitespace-pre-wrap">{{ $contactMessage->message }}</div>
            </div>

            {{-- Notes --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Interna anteckningar</h3>
                <flux:textarea wire:model="notes" rows="3" placeholder="Anteckningar syns bara för admins…" />
                <div class="mt-2 flex justify-end">
                    <flux:button wire:click="saveNotes" size="sm" variant="ghost">Spara anteckningar</flux:button>
                </div>
            </div>

        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">

            {{-- Sender info --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h3 class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mb-3">Avsändare</h3>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-zinc-400">Namn</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $contactMessage->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400">E-post</dt>
                        <dd>
                            <a href="mailto:{{ $contactMessage->email }}" class="text-complement-light hover:underline">
                                {{ $contactMessage->email }}
                            </a>
                        </dd>
                    </div>
                    @if ($contactMessage->phone)
                        <div>
                            <dt class="text-xs text-zinc-400">Telefon</dt>
                            <dd>{{ $contactMessage->phone }}</dd>
                        </div>
                    @endif
                    @if ($contactMessage->ip_address)
                        <div>
                            <dt class="text-xs text-zinc-400">IP-adress</dt>
                            <dd class="font-mono text-xs text-zinc-400">{{ $contactMessage->ip_address }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4">
                    <a href="mailto:{{ $contactMessage->email }}?subject=Re: {{ rawurlencode($contactMessage->subject) }}"
                       class="btn btn-primary btn-sm w-full text-center">
                        <x-bootstrap-icon name="reply" class="size-4" /> Svara via e-post
                    </a>
                </div>
            </div>

            {{-- Actions --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5 space-y-2">
                <h3 class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mb-3">Åtgärder</h3>

                @if ($contactMessage->status !== 'replied')
                    <flux:button wire:click="markReplied" variant="ghost" class="w-full" size="sm">
                        <x-bootstrap-icon name="check2" class="size-4" /> Markera som besvarad
                    </flux:button>
                @else
                    <p class="text-xs text-zinc-400">
                        Besvarad av {{ $contactMessage->repliedBy?->name ?? '–' }}
                        {{ $contactMessage->replied_at?->format('d/m Y H:i') }}
                    </p>
                @endif

                @if ($contactMessage->status !== 'spam')
                    <flux:button wire:click="markSpam" variant="ghost" class="w-full" size="sm"
                        x-data x-on:click="confirm('Markera som skräppost?') || $event.preventDefault()">
                        <x-bootstrap-icon name="shield-x" class="size-4" /> Skräppost
                    </flux:button>
                @endif

                <flux:button wire:click="delete" variant="ghost" class="w-full text-red-600 dark:text-red-400" size="sm"
                    x-data x-on:click="confirm('Radera meddelandet permanent?') || $event.preventDefault()">
                    <x-bootstrap-icon name="trash3" class="size-4" /> Radera
                </flux:button>
            </div>

        </div>
    </div>
</div>
