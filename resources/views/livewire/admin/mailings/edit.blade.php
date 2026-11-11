<?php

use App\Mail\MailingMail;
use App\Models\AuditLog;
use App\Models\Content\Mailing;
use App\Models\Content\MailingRecipient;
use App\Models\Content\MailingUnsubscribe;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Redigera utskick');

new class extends Component {

    public Mailing $mailing;

    // Content tab
    public string $title    = '';
    public string $category = '';
    public string $subject  = '';
    public string $bodyHtml = '';

    // Add-recipients state
    public string $addMethod    = '';  // all_active | section | manual
    public ?int   $sectionId    = null;
    public string $manualEmail  = '';

    // Send tab
    public string $testEmail    = '';
    public string $scheduledAt  = '';

    public function mount(Mailing $mailing): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);

        $this->mailing  = $mailing;
        $this->title    = $mailing->title;
        $this->category = $mailing->category ?? '';
        $this->subject  = $mailing->subject;
        $this->bodyHtml = $mailing->body_html;
        $this->testEmail = Auth::user()->email ?? '';
    }

    #[Computed]
    public function sections()
    {
        return Section::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function recipients()
    {
        $recipients = $this->mailing->recipients()->with('member')->orderBy('email')->get();

        if ($this->mailing->isDraft()) {
            return $recipients;
        }

        // Enrich with open data and unsubscribe status for sent/sending mailings
        $unsubscribed = MailingUnsubscribe::pluck('email')->map(fn ($e) => strtolower($e))->flip();

        $openMap = \App\Models\Content\MailLog::where('mailing_id', $this->mailing->id)
            ->get(['recipient_email', 'open_count', 'first_opened_at', 'delivery_status'])
            ->keyBy(fn ($l) => strtolower($l->recipient_email));

        return $recipients->map(function ($r) use ($unsubscribed, $openMap) {
            $log = $openMap->get(strtolower($r->email));
            $r->is_unsubscribed = $unsubscribed->has(strtolower($r->email));
            $r->open_count      = $log?->open_count ?? 0;
            $r->first_opened_at = $log?->first_opened_at;
            $r->mail_failed     = $log && $log->delivery_status === 'failed';

            // Single status for filtering
            $r->filter_status = match (true) {
                ! $r->sent_at              => 'pending',
                $r->mail_failed            => 'failed',
                $r->is_unsubscribed        => 'skipped',
                $log === null              => 'skipped',
                $r->open_count > 0         => 'opened',
                default                    => 'delivered',
            };

            return $r;
        });
    }

    // ── Content ──────────────────────────────────────────────────────────────

    public function saveContent(): void
    {
        abort_unless($this->mailing->isEditable(), 422);

        $this->validate([
            'title'    => 'required|string|max:255',
            'category' => 'nullable|string',
            'subject'  => 'required|string|max:255',
            'bodyHtml' => 'required|string',
        ], [
            'title.required'   => 'Titel krävs.',
            'subject.required' => 'Ämne krävs.',
            'bodyHtml.required'=> 'Brödtext krävs.',
        ]);

        $this->mailing->update([
            'title'    => $this->title,
            'category' => $this->category ?: null,
            'subject'  => $this->subject,
            'body_html'=> $this->bodyHtml,
        ]);

        AuditLog::record(action: 'mailing_updated', area: 'mailings', subject: $this->mailing->title, auditable: $this->mailing);

        $this->dispatch('content-saved');
    }

    // ── Recipients ───────────────────────────────────────────────────────────

    public function addAllActive(): void
    {
        abort_unless($this->mailing->isEditable(), 422);

        $members = Member::where('status', Member::STATUS_ACTIVE)
            ->whereNotNull('email')
            ->get(['id', 'email']);

        $rows = $members->map(fn ($m) => [
            'mailing_id' => $this->mailing->id,
            'email'      => $m->email,
            'member_id'  => $m->id,
        ])->toArray();

        foreach ($rows as $row) {
            MailingRecipient::firstOrCreate(
                ['mailing_id' => $row['mailing_id'], 'email' => $row['email']],
                ['member_id'  => $row['member_id']]
            );
        }

        $this->mailing->update(['recipients_count' => $this->mailing->recipients()->count()]);
        unset($this->recipients);
    }

    public function addSection(): void
    {
        abort_unless($this->mailing->isEditable(), 422);
        $this->validate(['sectionId' => 'required|exists:sections,id']);

        $members = Member::whereHas('sections', fn ($q) => $q->where('sections.id', $this->sectionId)->whereNull('section_memberships.left_at'))
            ->where('status', Member::STATUS_ACTIVE)
            ->whereNotNull('email')
            ->get(['id', 'email']);

        foreach ($members as $m) {
            MailingRecipient::firstOrCreate(
                ['mailing_id' => $this->mailing->id, 'email' => $m->email],
                ['member_id'  => $m->id]
            );
        }

        $this->mailing->update(['recipients_count' => $this->mailing->recipients()->count()]);
        $this->sectionId = null;
        unset($this->recipients);
    }

    public function addManual(): void
    {
        abort_unless($this->mailing->isEditable(), 422);
        $this->validate(['manualEmail' => 'required|email'], ['manualEmail.required' => 'E-postadress krävs.', 'manualEmail.email' => 'Ogiltig e-postadress.']);

        $email  = strtolower(trim($this->manualEmail));
        $member = Member::where('email', $email)->first();

        MailingRecipient::firstOrCreate(
            ['mailing_id' => $this->mailing->id, 'email' => $email],
            ['member_id'  => $member?->id]
        );

        $this->mailing->update(['recipients_count' => $this->mailing->recipients()->count()]);
        $this->manualEmail = '';
        unset($this->recipients);
    }

    public function removeRecipient(string $email): void
    {
        abort_unless($this->mailing->isEditable(), 422);

        MailingRecipient::where('mailing_id', $this->mailing->id)
            ->where('email', $email)
            ->delete();

        $this->mailing->update(['recipients_count' => $this->mailing->recipients()->count()]);
        unset($this->recipients);
    }

    public function clearAllRecipients(): void
    {
        abort_unless($this->mailing->isEditable(), 422);
        $this->mailing->recipients()->delete();
        $this->mailing->update(['recipients_count' => 0]);
        unset($this->recipients);
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    public function sendTest(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);
        $this->validate(['testEmail' => 'required|email'], ['testEmail.email' => 'Ogiltig e-postadress.']);

        $fakeMember = Member::where('email', $this->testEmail)->first()
            ?? new Member(['first_name' => 'Test', 'last_name' => 'Testsson', 'email' => $this->testEmail]);

        $recipient = new MailingRecipient(['email' => $this->testEmail]);
        $recipient->setRelation('member', $fakeMember);

        Mail::to($this->testEmail)->send(new MailingMail($this->mailing, $recipient));

        $this->dispatch('test-sent');
    }

    public function sendNow(): void
    {
        abort_unless($this->mailing->isEditable(), 422);
        abort_unless($this->mailing->recipients()->exists(), 422);

        $this->mailing->update(['status' => Mailing::STATUS_SENDING, 'scheduled_at' => null]);

        AuditLog::record(action: 'mailing_queued', area: 'mailings', subject: $this->mailing->title, auditable: $this->mailing);

        $this->mailing->refresh();
    }

    public function schedule(): void
    {
        abort_unless($this->mailing->isEditable(), 422);
        abort_unless($this->mailing->recipients()->exists(), 422);

        $this->validate([
            'scheduledAt' => ['required', 'date', 'after:now'],
        ], [
            'scheduledAt.required' => 'Välj ett datum och tid.',
            'scheduledAt.after'    => 'Schemaläggning måste vara i framtiden.',
        ]);

        $this->mailing->update([
            'status'       => Mailing::STATUS_SCHEDULED,
            'scheduled_at' => $this->scheduledAt,
        ]);

        AuditLog::record(action: 'mailing_scheduled', area: 'mailings', subject: $this->mailing->title, auditable: $this->mailing);

        $this->mailing->refresh();
    }

    public function cancel(): void
    {
        abort_unless(
            in_array($this->mailing->status, [Mailing::STATUS_SENDING, Mailing::STATUS_SCHEDULED]),
            422
        );

        $this->mailing->update(['status' => Mailing::STATUS_CANCELLED]);

        AuditLog::record(action: 'mailing_cancelled', area: 'mailings', subject: $this->mailing->title, auditable: $this->mailing);

        $this->mailing->refresh();
    }

    public function duplicate(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);

        $copy = Mailing::create([
            'mailing_template_id' => $this->mailing->mailing_template_id,
            'title'               => $this->mailing->title . ' (kopia)',
            'category'            => $this->mailing->category,
            'subject'             => $this->mailing->subject,
            'body_html'           => $this->mailing->body_html,
            'status'              => Mailing::STATUS_DRAFT,
            'created_by'          => Auth::id(),
        ]);

        AuditLog::record(action: 'mailing_duplicated', area: 'mailings', subject: $copy->title, auditable: $copy);

        $this->redirect(route('admin.mailings.edit', $copy), navigate: true);
    }

    #[Computed]
    public function stats(): array
    {
        if (! $this->mailing->isSent()) {
            return [];
        }

        $total     = MailingRecipient::where('mailing_id', $this->mailing->id)->count();
        $logs      = \App\Models\Content\MailLog::where('mailing_id', $this->mailing->id)->get();
        $delivered = $logs->where('delivery_status', \App\Models\Content\MailLog::STATUS_SENT)->count();
        $failed    = $logs->where('delivery_status', \App\Models\Content\MailLog::STATUS_FAILED)->count();
        $opened    = $logs->where('open_count', '>', 0)->count();
        $skipped   = $total - $delivered - $failed;

        return [
            'total'     => $total,
            'delivered' => $delivered,
            'failed'    => $failed,
            'opened'    => $opened,
            'skipped'   => max(0, $skipped),
            'open_rate' => $delivered > 0 ? round($opened / $delivered * 100) : 0,
        ];
    }
}; ?>

<x-slot:header>
    <x-page-header permission="mailings.manage" :title="$mailing->title" subtitle="Utskick">
        <div class="flex items-center gap-2">
            <button wire:click="duplicate" type="button"
                    class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                <x-bootstrap-icon name="copy" class="inline size-4" /> Klona
            </button>
            @if(in_array($mailing->status, [\App\Models\Content\Mailing::STATUS_SENDING, \App\Models\Content\Mailing::STATUS_SCHEDULED]))
                <button wire:click="cancel" type="button"
                        wire:confirm="Avbryt utskicket '{{ $mailing->title }}'? Redan skickade mail återkallas inte."
                        class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">
                    <x-bootstrap-icon name="x-circle" class="inline size-4" /> Avbryt utskick
                </button>
            @endif
            <a href="{{ route('admin.mailings.index') }}" wire:navigate
               class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                ← Tillbaka
            </a>
        </div>
    </x-page-header>
</x-slot:header>

<div
    x-data="{ tab: 'innehall' }"
    x-on:content-saved.window="$dispatch('notify', { message: 'Innehåll sparat.' })"
    x-on:test-sent.window="$dispatch('notify', { message: 'Testmail skickat.' })"
    @if($mailing->isSending() || $mailing->isScheduled()) wire:poll.5000ms @endif
>

    {{-- Scheduled banner --}}
    @if($mailing->isScheduled())
        <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium text-blue-800 dark:text-blue-300">
                    <x-bootstrap-icon name="clock" class="inline size-4" />
                    Schemalagd för sändning {{ $mailing->scheduled_at?->format('d/m Y H:i') }}
                </p>
                <span class="text-xs text-blue-500 dark:text-blue-400">Sidan uppdateras automatiskt.</span>
            </div>
        </div>
    @endif

    {{-- Sending progress banner --}}
    @if($mailing->isSending())
        @php
            $total = $mailing->recipients()->count();
            $done  = $mailing->recipients()->whereNotNull('sent_at')->count();
            $pct   = $total > 0 ? round($done / $total * 100) : 0;
        @endphp
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                    <x-bootstrap-icon name="send" class="inline size-4 animate-pulse" />
                    Skickar… {{ $done }} / {{ $total }} mottagare
                </p>
                <span class="text-sm text-amber-600 dark:text-amber-400">{{ $pct }}%</span>
            </div>
            <div class="h-2 w-full rounded-full bg-amber-200 dark:bg-amber-800">
                <div class="h-2 rounded-full bg-amber-500 transition-all" style="width: {{ $pct }}%"></div>
            </div>
            <p class="mt-2 text-xs text-amber-600 dark:text-amber-500">Sidan uppdateras automatiskt var 5:e sekund.</p>
        </div>
    @endif

    {{-- Status badge --}}
    <div class="mb-6 flex items-center gap-3">
        @php
            $statusLabel = match($mailing->status) {
                'draft'     => 'Utkast',
                'scheduled' => 'Schemalagd',
                'sending'   => 'Skickas',
                'sent'      => 'Skickat',
                'cancelled' => 'Avbrutet',
                default     => $mailing->status,
            };
            $statusClass = match($mailing->status) {
                'draft'     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
                'scheduled' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                'sending'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                'sent'      => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                'cancelled' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                default     => 'bg-zinc-100 text-zinc-600',
            };
        @endphp
        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClass }}">
            {{ $statusLabel }}
        </span>
        @if($mailing->sent_at)
            <span class="text-sm text-zinc-400">Skickat {{ $mailing->sent_at->format('d/m Y H:i') }}</span>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="mb-6 border-b border-zinc-200 dark:border-zinc-700">
        <nav class="-mb-px flex gap-6">
            <button @click="tab = 'innehall'"
                    :class="tab === 'innehall' ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors">
                <x-bootstrap-icon name="pencil" class="size-4" /> Innehåll
            </button>
            <button @click="tab = 'mottagare'"
                    :class="tab === 'mottagare' ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors">
                <x-bootstrap-icon name="people" class="size-4" /> Mottagare
                <span class="ml-0.5 rounded-full bg-zinc-200 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                    {{ $mailing->recipients_count ?: $this->recipients->count() }}
                </span>
            </button>
            <button @click="tab = 'forhandsgranska'"
                    :class="tab === 'forhandsgranska' ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors">
                <x-bootstrap-icon name="eye" class="size-4" /> Förhandsgranska
            </button>
            <button @click="tab = 'skicka'"
                    :class="tab === 'skicka' ? 'border-brand text-brand-dark dark:text-brand border-b-2 font-semibold' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    class="flex items-center gap-1.5 px-1 py-3 text-sm transition-colors">
                <x-bootstrap-icon name="send" class="size-4" /> Skicka
            </button>
        </nav>
    </div>

    {{-- ── TAB: Innehåll ────────────────────────────────────────────────────── --}}
    <div x-show="tab === 'innehall'" x-cloak>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <form wire:submit="saveContent" class="space-y-5 lg:col-span-2">

                @if(! $mailing->isEditable())
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        <x-bootstrap-icon name="lock" class="inline size-4" /> Utskicket kan inte redigeras längre (status: {{ $statusLabel }}).
                    </div>
                @endif

                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
                    <div>
                        <label class="lbl">Intern titel</label>
                        <input wire:model="title" type="text" class="inp w-full" {{ $mailing->isEditable() ? '' : 'disabled' }} />
                        @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Kategori <span class="font-normal text-zinc-400">valfritt</span></label>
                        <select wire:model="category" class="inp w-full" {{ $mailing->isEditable() ? '' : 'disabled' }}>
                            <option value="">— Ingen kategori —</option>
                            @foreach(\App\Support\NotificationCategory::forSelect() as $slug => $label)
                                <option value="{{ $slug }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-zinc-400">Mottagare som avprenumererat från kategorin hoppas över vid sändning.</p>
                    </div>
                    <div>
                        <label class="lbl">Ämnesrad</label>
                        <input wire:model="subject" type="text" class="inp w-full" {{ $mailing->isEditable() ? '' : 'disabled' }} />
                        @error('subject') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Brödtext</label>
                        <div class="mt-1" wire:ignore>
                            <input type="hidden" id="body-input" wire:model="bodyHtml">
                            <trix-editor input="body-input"
                                         class="trix-content rounded-lg border border-zinc-300 dark:border-zinc-600"
                                         style="min-height:320px;padding:1rem;"
                                         data-direct-upload-url=""
                                         data-blob-url-template=""
                                         {{ $mailing->isEditable() ? '' : 'contenteditable="false"' }}></trix-editor>
                        </div>
                        @error('bodyHtml') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if($mailing->isEditable())
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary">Spara innehåll</button>
                    </div>
                @endif

            </form>

            <aside class="space-y-4">
                <x-mailing-variable-list />
            </aside>
        </div>
    </div>

    {{-- ── TAB: Mottagare ───────────────────────────────────────────────────── --}}
    <div x-show="tab === 'mottagare'" x-cloak>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            <div class="space-y-4 lg:col-span-2">

                @if($mailing->isEditable())
                    {{-- Add recipients panel --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
                        <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Lägg till mottagare</p>

                        <div class="flex flex-wrap gap-2">
                            <button wire:click="addAllActive" type="button"
                                    class="btn btn-secondary btn-sm">
                                <x-bootstrap-icon name="people-fill" class="size-4" /> Alla aktiva medlemmar
                            </button>
                        </div>

                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <label class="lbl">Sektion</label>
                                <select wire:model="sectionId" class="inp w-full">
                                    <option value="">— Välj sektion —</option>
                                    @foreach($this->sections as $sec)
                                        <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button wire:click="addSection" type="button" class="btn btn-secondary btn-sm">
                                Lägg till
                            </button>
                        </div>
                        @error('sectionId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <label class="lbl">Manuell e-postadress</label>
                                <input wire:model="manualEmail" type="email" class="inp w-full"
                                       placeholder="namn@example.com" />
                            </div>
                            <button wire:click="addManual" type="button" class="btn btn-secondary btn-sm">
                                Lägg till
                            </button>
                        </div>
                        @error('manualEmail') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Recipient list --}}
                @if($this->recipients->isEmpty())
                    <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                        <x-bootstrap-icon name="people" class="mx-auto mb-2 size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="text-sm text-zinc-500">Inga mottagare tillagda ännu.</p>
                    </div>
                @else
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        @php
                            $filterCounts = ! $mailing->isDraft()
                                ? $this->recipients->countBy('filter_status')->all()
                                : [];
                            $deliveredCount = ($filterCounts['delivered'] ?? 0) + ($filterCounts['opened'] ?? 0);
                        @endphp

                        <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100 dark:border-zinc-800">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ $this->recipients->count() }} mottagare
                            </p>
                            @if($mailing->isEditable())
                                <button wire:click="clearAllRecipients"
                                        wire:confirm="Ta bort alla mottagare?"
                                        type="button" class="text-xs text-red-500 hover:text-red-700">
                                    Rensa alla
                                </button>
                            @endif
                        </div>

                        @if($mailing->isDraft())
                            {{-- Draft: simple list with remove button --}}
                            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800 max-h-96 overflow-y-auto">
                                @foreach($this->recipients as $recipient)
                                    <li class="flex items-center justify-between px-5 py-2.5">
                                        <div>
                                            <span class="text-sm text-zinc-900 dark:text-white">{{ $recipient->email }}</span>
                                            @if($recipient->member)
                                                <span class="ml-2 text-xs text-zinc-400">{{ $recipient->member->full_name }}</span>
                                            @endif
                                        </div>
                                        <button wire:click="removeRecipient('{{ $recipient->email }}')"
                                                type="button"
                                                class="text-zinc-400 hover:text-red-500">
                                            <x-bootstrap-icon name="x" class="size-4" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            {{-- Sent/sending: filterable status table --}}
                            <div x-data="{ filter: 'all' }">

                                {{-- Filter buttons --}}
                                <div class="flex flex-wrap gap-1.5 px-5 py-3 border-b border-zinc-100 dark:border-zinc-800">
                                    @php
                                        $filters = [
                                            'all'       => ['label' => 'Alla',        'count' => $this->recipients->count(), 'class' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200'],
                                            'delivered' => ['label' => 'Levererade',  'count' => $deliveredCount,            'class' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'],
                                            'opened'    => ['label' => 'Öppnade',     'count' => $filterCounts['opened'] ?? 0,'class' => 'bg-brand/20 text-brand-dark dark:bg-brand/10 dark:text-brand'],
                                            'failed'    => ['label' => 'Misslyckade', 'count' => $filterCounts['failed'] ?? 0,'class' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
                                            'skipped'   => ['label' => 'Hoppade',     'count' => $filterCounts['skipped'] ?? 0,'class' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'],
                                        ];
                                        if (isset($filterCounts['pending'])) {
                                            $filters['pending'] = ['label' => 'Väntar', 'count' => $filterCounts['pending'] ?? 0, 'class' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'];
                                        }
                                    @endphp

                                    @foreach($filters as $key => $f)
                                        @if($f['count'] > 0 || $key === 'all')
                                            <button type="button"
                                                    @click="filter = '{{ $key }}'"
                                                    :class="filter === '{{ $key }}' ? 'ring-2 ring-offset-1 ring-zinc-400 dark:ring-zinc-500' : 'opacity-70 hover:opacity-100'"
                                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition {{ $f['class'] }}">
                                                {{ $f['label'] }}
                                                <span class="rounded-full bg-white/40 px-1 dark:bg-black/20">{{ $f['count'] }}</span>
                                            </button>
                                        @endif
                                    @endforeach
                                </div>

                                <div class="max-h-[32rem] overflow-y-auto">
                                    <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                                        <thead class="sticky top-0 bg-zinc-50 dark:bg-zinc-800">
                                            <tr>
                                                <th class="px-5 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Mottagare</th>
                                                <th class="px-5 py-2.5 text-center text-xs font-semibold uppercase tracking-wider text-zinc-400">Status</th>
                                                <th class="hidden px-5 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 sm:table-cell">Skickat</th>
                                                <th class="hidden px-5 py-2.5 text-center text-xs font-semibold uppercase tracking-wider text-zinc-400 md:table-cell">Öppnat</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                            @foreach($this->recipients as $recipient)
                                                @php
                                                    $fs = $recipient->filter_status;
                                                    // "levererade"-filtret inkluderar både delivered och opened
                                                    $showExpr = "filter === 'all' || filter === '{$fs}' || (filter === 'delivered' && ('{$fs}' === 'delivered' || '{$fs}' === 'opened'))";
                                                @endphp
                                                <tr x-show="{{ $showExpr }}"
                                                    class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                                    <td class="px-5 py-2.5">
                                                        <p class="text-zinc-900 dark:text-white">{{ $recipient->email }}</p>
                                                        @if($recipient->member)
                                                            <p class="text-xs text-zinc-400">{{ $recipient->member->full_name }}</p>
                                                        @endif
                                                    </td>
                                                    <td class="px-5 py-2.5 text-center">
                                                        @if($recipient->is_unsubscribed)
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                                                <x-bootstrap-icon name="slash-circle" class="size-3" /> Avprenumererad
                                                            </span>
                                                        @elseif($recipient->mail_failed)
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                                <x-bootstrap-icon name="x-circle" class="size-3" /> Misslyckades
                                                            </span>
                                                        @elseif($recipient->filter_status === 'skipped')
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                                                <x-bootstrap-icon name="dash-circle" class="size-3" /> Hoppades
                                                            </span>
                                                        @elseif($recipient->sent_at)
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                                <x-bootstrap-icon name="check" class="size-3" /> Skickat
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                                                <x-bootstrap-icon name="hourglass-split" class="size-3" /> Väntar
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td class="hidden px-5 py-2.5 text-right text-xs text-zinc-400 sm:table-cell">
                                                        {{ $recipient->sent_at?->format('d/m H:i') ?? '—' }}
                                                    </td>
                                                    <td class="hidden px-5 py-2.5 text-center md:table-cell">
                                                        @if($recipient->open_count > 0)
                                                            <span class="inline-flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                                                <x-bootstrap-icon name="eye" class="size-3" />
                                                                {{ $recipient->open_count }}×
                                                            </span>
                                                        @else
                                                            <span class="text-xs text-zinc-300 dark:text-zinc-600">—</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                {{-- Empty state for active filter --}}
                                <p x-show="filter !== 'all' && document.querySelectorAll('#recipient-table tr[x-show]').length === 0"
                                   class="px-5 py-6 text-center text-sm text-zinc-400">
                                    Inga mottagare matchar filtret.
                                </p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <aside class="space-y-3 text-sm text-zinc-500 dark:text-zinc-400">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">Hur det fungerar</p>
                    <ul class="space-y-1.5 text-xs">
                        <li>Mottagarlistan är en platt lista av e-postadresser.</li>
                        <li>Du kan lägga till samma e-post från flera regler — den läggs bara till en gång.</li>
                        <li>Manuell borttagning tar bort enskilda mottagare.</li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>

    {{-- ── TAB: Förhandsgranska ─────────────────────────────────────────────── --}}
    <div x-show="tab === 'forhandsgranska'" x-cloak>
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                <p class="text-sm text-zinc-500">
                    Ämne: <span class="font-medium text-zinc-900 dark:text-white">{{ $mailing->subject }}</span>
                </p>
                <p class="text-xs text-zinc-400 mt-0.5">Variabler som @{{fornamn}} visas som-är i förhandsgranskning.</p>
            </div>
            <div class="p-6 prose prose-zinc dark:prose-invert max-w-none">
                {!! $mailing->body_html !!}
            </div>
        </div>
    </div>

    {{-- ── TAB: Skicka ──────────────────────────────────────────────────────── --}}
    <div x-show="tab === 'skicka'" x-cloak>
        <div class="mx-auto max-w-lg space-y-6">

            {{-- Statistics (sent mailings) --}}
            @if($mailing->isSent() && count($this->stats))
                @php $s = $this->stats; @endphp
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-4 font-semibold text-zinc-900 dark:text-white">Resultat</h3>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $s['total'] }}</p>
                            <p class="text-xs text-zinc-500">Totalt</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $s['delivered'] }}</p>
                            <p class="text-xs text-zinc-500">Levererade</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-brand-dark dark:text-brand">{{ $s['open_rate'] }}%</p>
                            <p class="text-xs text-zinc-500">Öppningsfrekvens</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-zinc-700 dark:text-zinc-300">{{ $s['opened'] }}</p>
                            <p class="text-xs text-zinc-500">Öppnade</p>
                        </div>
                        @if($s['skipped'] > 0)
                            <div class="text-center">
                                <p class="text-2xl font-bold text-zinc-400">{{ $s['skipped'] }}</p>
                                <p class="text-xs text-zinc-500">Hoppade över</p>
                            </div>
                        @endif
                        @if($s['failed'] > 0)
                            <div class="text-center">
                                <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $s['failed'] }}</p>
                                <p class="text-xs text-zinc-500">Misslyckade</p>
                            </div>
                        @endif
                    </div>
                    <p class="mt-4 text-xs text-zinc-400">
                        Öppningsfrekvens baseras på spårningspixel — bildblockering i e-postklienter kan ge lägre siffror än verkligheten.
                    </p>
                </div>
            @endif

            {{-- Test send --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
                <p class="font-semibold text-zinc-900 dark:text-white">Skicka testmail</p>
                <p class="text-sm text-zinc-500">Skickar till en enda adress med faktiska variabelvärden om adressen är kopplad till en medlem.</p>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="lbl">E-postadress</label>
                        <input wire:model="testEmail" type="email" class="inp w-full" />
                        @error('testEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button wire:click="sendTest" type="button" class="btn btn-secondary">
                        <x-bootstrap-icon name="send" class="size-4" /> Skicka test
                    </button>
                </div>
            </div>

            {{-- Send / Schedule --}}
            @if($mailing->isEditable())
                @php $count = $mailing->recipients_count ?: $this->recipients->count(); @endphp
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 space-y-5">
                    <p class="font-semibold text-zinc-900 dark:text-white">Skicka till alla mottagare</p>

                    @if($count === 0)
                        <p class="text-sm text-amber-600 dark:text-amber-400">
                            <x-bootstrap-icon name="exclamation-triangle" class="inline size-4" />
                            Inga mottagare tillagda. Gå till fliken Mottagare och lägg till mottagare först.
                        </p>
                    @else
                        <p class="text-sm text-zinc-500">
                            Utskicket skickas till <strong class="text-zinc-900 dark:text-white">{{ $count }} mottagare</strong>.
                        </p>

                        {{-- Send now --}}
                        <div>
                            <button wire:click="sendNow"
                                    wire:confirm="Skicka utskicket '{{ $mailing->title }}' till {{ $count }} mottagare nu?"
                                    type="button"
                                    class="btn btn-primary w-full">
                                <x-bootstrap-icon name="send-fill" class="size-4" /> Skicka nu
                            </button>
                        </div>

                        {{-- Schedule --}}
                        <div class="border-t border-zinc-100 pt-4 dark:border-zinc-800">
                            <p class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">Eller schemalägg</p>
                            <div class="flex items-end gap-2">
                                <div class="flex-1">
                                    <label class="lbl">Datum och tid</label>
                                    <input wire:model="scheduledAt" type="datetime-local"
                                           min="{{ now()->addMinutes(5)->format('Y-m-d\TH:i') }}"
                                           class="inp w-full" />
                                    @error('scheduledAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <button wire:click="schedule" type="button" class="btn btn-secondary">
                                    <x-bootstrap-icon name="clock" class="size-4" /> Schemalägg
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @elseif($mailing->isScheduled())
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-6 dark:border-blue-800 dark:bg-blue-900/20">
                    <p class="text-sm font-medium text-blue-800 dark:text-blue-300">
                        <x-bootstrap-icon name="clock" class="inline size-4" />
                        Schemalagd för {{ $mailing->scheduled_at?->format('d/m Y H:i') }}
                    </p>
                    <p class="mt-1 text-xs text-blue-600 dark:text-blue-400">Använd "Avbryt utskick"-knappen ovan om du vill stoppa den.</p>
                </div>
            @elseif($mailing->isSent())
                <div class="rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
                    <p class="text-sm font-medium text-green-800 dark:text-green-300">
                        <x-bootstrap-icon name="check-circle-fill" class="inline size-4" />
                        Utskicket skickades {{ $mailing->sent_at?->format('d/m Y H:i') }}.
                    </p>
                </div>
            @elseif($mailing->isCancelled())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                    <p class="text-sm font-medium text-red-800 dark:text-red-300">
                        <x-bootstrap-icon name="x-circle" class="inline size-4" />
                        Utskicket avbröts.
                    </p>
                </div>
            @endif
        </div>
    </div>

</div>

<script>
    document.addEventListener('trix-file-accept', e => e.preventDefault());

    document.addEventListener('livewire:navigated', () => {
        const input = document.getElementById('body-input');
        const editor = document.querySelector('trix-editor');
        if (input && editor?.editor && input.value) {
            editor.editor.loadHTML(input.value);
        }
    });
</script>
