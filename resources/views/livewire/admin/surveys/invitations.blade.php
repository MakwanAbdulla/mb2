<?php

use App\Models\AuditLog;
use App\Models\Survey\Survey;
use App\Models\Survey\SurveyInvitation;
use App\Notifications\SurveyInvitationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Inbjudningar');

new class extends Component {
    public Survey $survey;
    public string $emailInput = '';

    public function mount(Survey $survey): void
    {
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);
        $this->survey = $survey;
    }

    #[Computed]
    public function invitations()
    {
        return $this->survey->invitations()->orderByDesc('created_at')->get();
    }

    public function addInvitations(): void
    {
        $this->validate(['emailInput' => 'required|string']);

        $emails = collect(preg_split('/[\s,;]+/', $this->emailInput))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            $this->addError('emailInput', 'Inga giltiga e-postadresser hittades.');
            return;
        }

        $existing = $this->survey->invitations()->pluck('email')->map('strtolower');
        $new      = $emails->diff($existing);

        foreach ($new as $email) {
            SurveyInvitation::create([
                'survey_id' => $this->survey->id,
                'email'     => $email,
                'token'     => Str::random(64),
            ]);
        }

        AuditLog::record(
            action: 'survey_invitations_added',
            area: 'surveys',
            subject: $this->survey->title . ' (' . $new->count() . ' adresser)',
            auditable: $this->survey,
        );

        $this->emailInput = '';
        unset($this->invitations);

        $skipped = $emails->count() - $new->count();
        $msg     = $new->count() . ' inbjudning(ar) tillagda.';
        if ($skipped > 0) {
            $msg .= ' ' . $skipped . ' redan inbjudna.';
        }
        $this->dispatch('notify', message: $msg);
    }

    public function send(int $id): void
    {
        $invitation = SurveyInvitation::findOrFail($id);

        Notification::route('mail', $invitation->email)
            ->notify(new SurveyInvitationNotification($invitation));

        $invitation->update(['sent_at' => now()]);
        unset($this->invitations);

        AuditLog::record(
            action: 'survey_invitation_sent',
            area: 'surveys',
            subject: $this->survey->title . ' → ' . $invitation->email,
            auditable: $this->survey,
        );

        $this->dispatch('notify', message: 'Inbjudan skickad till ' . $invitation->email);
    }

    public function sendAll(): void
    {
        $unsent = $this->survey->invitations()
            ->whereNull('sent_at')
            ->whereNull('responded_at')
            ->get();

        foreach ($unsent as $invitation) {
            Notification::route('mail', $invitation->email)
                ->notify(new SurveyInvitationNotification($invitation));
            $invitation->update(['sent_at' => now()]);
        }

        unset($this->invitations);

        AuditLog::record(
            action: 'survey_invitations_sent',
            area: 'surveys',
            subject: $this->survey->title . ' (' . $unsent->count() . ' st)',
            auditable: $this->survey,
        );

        $this->dispatch('notify', message: $unsent->count() . ' inbjudningar skickade.');
    }

    public function sendReminder(): void
    {
        $invitations = $this->survey->invitations()
            ->whereNotNull('sent_at')
            ->whereNull('responded_at')
            ->get();

        foreach ($invitations as $invitation) {
            Notification::route('mail', $invitation->email)
                ->notify(new SurveyInvitationNotification($invitation));
        }

        AuditLog::record(
            action: 'survey_reminder_sent',
            area: 'surveys',
            subject: $this->survey->title . ' (' . $invitations->count() . ' st)',
            auditable: $this->survey,
        );

        $this->dispatch('notify', message: 'Påminnelse skickad till ' . $invitations->count() . ' mottagare.');
    }

    public function remove(int $id): void
    {
        $invitation = SurveyInvitation::findOrFail($id);
        $email      = $invitation->email;
        $invitation->delete();
        unset($this->invitations);

        $this->dispatch('notify', message: 'Inbjudan till ' . $email . ' borttagen.');
    }

    public function copyLink(int $id): void
    {
        $invitation = SurveyInvitation::findOrFail($id);
        $this->dispatch('copy-link', url: route('surveys.respond', $invitation->token));
    }
}; ?>

<div x-data x-on:copy-link.window="navigator.clipboard.writeText($event.detail.url)">
    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6">

        <div class="mb-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.surveys.edit', $survey) }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Redigera enkät</a>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Inbjudningar: {{ $survey->title }}</h1>
            @php $statusColors = ['draft' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300', 'active' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'closed' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400']; @endphp
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusColors[$survey->status->value] ?? '' }}">
                {{ $survey->status->label() }}
            </span>
        </div>

        <div class="space-y-6">
            {{-- Öppen länk --}}
            @if($survey->is_open)
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Öppen enkätlänk</span>
                    </div>
                    <div class="px-5 py-4">
                        <p class="mb-2 text-sm text-zinc-500 dark:text-zinc-400">Alla med länken kan svara utan inbjudan.</p>
                        <code class="block rounded bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ route('surveys.respond', 'open-'.$survey->id) }}
                        </code>
                    </div>
                </div>
            @endif

            {{-- Lägg till inbjudningar --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Lägg till inbjudningar</span>
                </div>
                <div class="px-5 py-4 space-y-3">
                    <div>
                        <label class="lbl">E-postadresser</label>
                        <textarea wire:model="emailInput" rows="4" class="inp w-full resize-none"
                                  placeholder="En adress per rad, eller kommaseparerade&#10;exempel@mail.se&#10;annan@mail.se"></textarea>
                        <p class="mt-1 text-xs text-zinc-400">Stöder komma, semikolon eller radbrytning som separator.</p>
                        @error('emailInput') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button wire:click="addInvitations" class="btn btn-primary btn-sm">Lägg till</button>
                        @php
                            $unsentCount = $this->invitations->whereNull('sent_at')->whereNull('responded_at')->count();
                            $pendingCount = $this->invitations->whereNotNull('sent_at')->whereNull('responded_at')->count();
                        @endphp
                        @if($unsentCount > 0)
                            <button wire:click="sendAll"
                                    wire:confirm="Skicka inbjudan till alla {{ $unsentCount }} ej skickade?"
                                    class="btn btn-ghost btn-sm">
                                Skicka alla ej skickade ({{ $unsentCount }})
                            </button>
                        @endif
                        @if($pendingCount > 0)
                            <button wire:click="sendReminder"
                                    wire:confirm="Skicka påminnelse till {{ $pendingCount }} som ännu inte svarat?"
                                    class="btn btn-ghost btn-sm">
                                Skicka påminnelse ({{ $pendingCount }})
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Lista --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                        Inbjudningar ({{ $this->invitations->count() }})
                    </span>
                    <div class="flex gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ $this->invitations->whereNotNull('responded_at')->count() }} svarade</span>
                        <span>{{ $this->invitations->whereNotNull('sent_at')->whereNull('responded_at')->count() }} skickade</span>
                        <span>{{ $this->invitations->whereNull('sent_at')->count() }} ej skickade</span>
                    </div>
                </div>
                @if($this->invitations->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">Inga inbjudningar ännu.</p>
                @else
                    <ul class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @foreach($this->invitations as $invitation)
                            <li class="flex items-center justify-between px-5 py-3">
                                <div>
                                    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $invitation->email }}</span>
                                    <div class="mt-0.5 flex gap-3 text-xs text-zinc-400">
                                        @if($invitation->responded_at)
                                            <span class="text-green-600 dark:text-green-400">✓ Svarat {{ $invitation->responded_at->diffForHumans() }}</span>
                                        @elseif($invitation->sent_at)
                                            <span>Skickad {{ $invitation->sent_at->diffForHumans() }}</span>
                                        @else
                                            <span class="text-amber-600 dark:text-amber-400">Ej skickad</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if(! $invitation->hasResponded())
                                        <button wire:click="send({{ $invitation->id }})"
                                                class="text-xs text-complement hover:underline dark:text-complement-light">
                                            {{ $invitation->sent_at ? 'Skicka igen' : 'Skicka' }}
                                        </button>
                                        <button wire:click="copyLink({{ $invitation->id }})"
                                                class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                            Kopiera länk
                                        </button>
                                    @endif
                                    <button wire:click="remove({{ $invitation->id }})"
                                            wire:confirm="Ta bort inbjudan till {{ $invitation->email }}?"
                                            class="text-xs text-red-500 hover:underline">Ta bort</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
