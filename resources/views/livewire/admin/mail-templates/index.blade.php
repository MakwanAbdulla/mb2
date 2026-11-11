<?php

use App\Mail\OrderInvoiceMail;
use App\Mail\PaymentReminderMail;
use App\Mail\MembershipRenewalMail;
use App\Models\AuditLog;
use App\Models\Content\MailTemplate;
use App\Services\MailTemplateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Mailmallar');

new class extends Component {

    public ?string $selectedKey = null;

    // Edit form
    public string $form_subject = '';
    public string $form_body    = '';
    public bool   $form_active  = true;

    // Validation warning (soft — does not block save)
    /** @var string[] */
    public array $unknownVarsWarning = [];

    // Test send
    public string $testEmail   = '';
    public ?string $testResult = null;
    public bool    $testOk     = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);
        $this->testEmail = Auth::user()->email ?? '';
    }

    #[Computed]
    public function templates(): array
    {
        // Merge DB records with static definitions so all keys always appear
        $dbTemplates = MailTemplate::all()->keyBy('key');

        return collect(MailTemplateService::TEMPLATES)
            ->map(function (array $def, string $key) use ($dbTemplates) {
                $db = $dbTemplates->get($key);
                return [
                    'key'         => $key,
                    'label'       => $def['label'],
                    'description' => $def['description'],
                    'variables'   => $def['variables'],
                    'is_active'   => $db?->is_active ?? true,
                    'exists'      => $db !== null,
                    'updated_at'  => $db?->updated_at,
                ];
            })
            ->values()
            ->toArray();
    }

    #[Computed]
    public function selected(): ?MailTemplate
    {
        return $this->selectedKey ? MailTemplate::findByKey($this->selectedKey) : null;
    }

    #[Computed]
    public function selectedDef(): ?array
    {
        return $this->selectedKey ? (MailTemplateService::TEMPLATES[$this->selectedKey] ?? null) : null;
    }

    public function selectTemplate(string $key): void
    {
        $this->selectedKey       = $key;
        $this->testResult        = null;
        $this->testOk            = false;
        $this->unknownVarsWarning = [];

        $template = MailTemplate::findByKey($key);
        if ($template) {
            $this->form_subject = $template->subject;
            $this->form_body    = $template->body;
            $this->form_active  = $template->is_active;
        } else {
            // Load defaults from seeder data (re-use seeder logic via service)
            $this->form_subject = '';
            $this->form_body    = '';
            $this->form_active  = true;
        }

        unset($this->selected, $this->selectedDef);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);
        if (! $this->selectedKey) return;

        $this->validate([
            'form_subject' => ['required', 'string', 'max:255'],
            'form_body'    => ['required', 'string'],
        ], [
            'form_subject.required' => 'Ämnesraden får inte vara tom.',
            'form_body.required'    => 'Brödtexten får inte vara tom.',
        ]);

        $def = MailTemplateService::TEMPLATES[$this->selectedKey];

        // Soft variable validation — warn about unknown {{placeholders}}, but still save
        preg_match_all('/\{\{([^}]+)\}\}/', $this->form_subject . ' ' . $this->form_body, $matches);
        $usedVars    = array_unique($matches[1] ?? []);
        $knownVars   = array_keys($def['variables'] ?? []);
        $this->unknownVarsWarning = array_values(array_diff($usedVars, $knownVars));

        MailTemplate::updateOrCreate(
            ['key' => $this->selectedKey],
            [
                'label'       => $def['label'],
                'description' => $def['description'],
                'subject'     => $this->form_subject,
                'body'        => $this->form_body,
                'is_active'   => $this->form_active,
            ]
        );

        AuditLog::record('mail_template_saved', 'system', $this->selectedKey, null);
        unset($this->selected, $this->templates);
        session()->flash('saved', empty($this->unknownVarsWarning) ? 'Mallen sparades.' : 'Mallen sparades med varningar.');
    }

    public function resetToDefault(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);
        if (! $this->selectedKey) return;

        // Re-seed only this key by calling the seeder inline
        (new \Database\Seeders\MailTemplateSeeder())->run();

        // Reload into form
        $template = MailTemplate::findByKey($this->selectedKey);
        if ($template) {
            $this->form_subject = $template->subject;
            $this->form_body    = $template->body;
            $this->form_active  = $template->is_active;
        }

        AuditLog::record('mail_template_reset', 'system', $this->selectedKey, null);
        unset($this->selected, $this->templates);
        session()->flash('saved', 'Standardtext återställd.');
    }

    public function sendTest(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);
        if (! $this->selectedKey) return;

        $this->validate([
            'testEmail' => ['required', 'email'],
        ], [
            'testEmail.required' => 'Ange en e-postadress.',
            'testEmail.email'    => 'Ogiltig e-postadress.',
        ]);

        $service   = app(MailTemplateService::class);
        $variables = $service->sampleVariables($this->selectedKey);
        $template  = MailTemplate::findByKey($this->selectedKey);

        if (! $template) {
            $this->testResult = 'Mallen är inte sparad än – spara först.';
            $this->testOk     = false;
            return;
        }

        try {
            $rendered = $service->render($this->selectedKey, $variables);
            if (! $rendered) {
                $this->testResult = 'Mallen är inaktiv eller kunde inte renderas.';
                $this->testOk     = false;
                return;
            }

            // Send raw rendered email (no structured details/CTA — just the body text)
            Mail::html(
                '<html><body style="font-family:sans-serif;max-width:580px;margin:0 auto;padding:24px">'
                . '<p style="font-size:11px;color:#888;border-bottom:1px solid #eee;padding-bottom:8px;margin-bottom:20px">'
                . '⚡ Testmeddelande – variablerna är exempeldata</p>'
                . $rendered['html']
                . '</body></html>',
                fn ($msg) => $msg->to($this->testEmail)->subject('[TEST] ' . $rendered['subject'])
            );

            $this->testResult = "Testmail skickades till {$this->testEmail}.";
            $this->testOk     = true;
        } catch (\Throwable $e) {
            $this->testResult = 'Fel vid utskick: ' . $e->getMessage();
            $this->testOk     = false;
        }
    }

    public function insertVariable(string $variable): void
    {
        // Handled client-side via Alpine — this action is a no-op fallback
    }
}; ?>

<x-slot:header>
    <x-page-header permission="settings.manage" title="Mailmallar" subtitle="Redigera ämnesrad och brödtext för transaktionella mail.">
    </x-page-header>
</x-slot:header>

<div>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6">

        @if(session('saved'))
            <div class="mb-4 flex items-center gap-2 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-400">
                <x-bootstrap-icon name="check-circle" class="size-4 shrink-0" />
                {{ session('saved') }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[300px_1fr]">

            {{-- Template list --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Mallar</p>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($this->templates as $t)
                        <button wire:click="selectTemplate('{{ $t['key'] }}')"
                                class="w-full px-4 py-3 text-left transition-colors
                                    {{ $selectedKey === $t['key']
                                        ? 'bg-brand/10 dark:bg-brand/10'
                                        : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ $t['label'] }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 leading-snug">
                                        {{ $t['description'] }}
                                    </p>
                                </div>
                                <div class="shrink-0 flex flex-col items-end gap-1 pt-0.5">
                                    @if(! $t['is_active'])
                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-400 dark:bg-zinc-800">Inaktiv</span>
                                    @endif
                                    @if(! $t['exists'])
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">Ej sparad</span>
                                    @endif
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Edit panel --}}
            @if($selectedKey && $this->selectedDef)
                <div class="space-y-5"
                     x-data="{
                        draftKey: 'mail_template_draft_{{ $selectedKey }}',
                        hasDraft: false,
                        draftTime: null,

                        init() {
                            this.checkDraft();
                            // Auto-save draft on any Livewire update to form fields
                            $watch('$wire.form_subject', () => this.saveDraft());
                            $watch('$wire.form_body',    () => this.saveDraft());
                        },

                        checkDraft() {
                            const raw = localStorage.getItem(this.draftKey);
                            if (!raw) return;
                            try {
                                const d = JSON.parse(raw);
                                // Only show banner if draft differs from current form
                                if (d.subject !== $wire.form_subject || d.body !== $wire.form_body) {
                                    this.hasDraft  = true;
                                    this.draftTime = d.savedAt ? new Date(d.savedAt).toLocaleString('sv-SE') : '';
                                }
                            } catch(e) { localStorage.removeItem(this.draftKey); }
                        },

                        saveDraft() {
                            localStorage.setItem(this.draftKey, JSON.stringify({
                                subject: $wire.form_subject,
                                body:    $wire.form_body,
                                savedAt: new Date().toISOString(),
                            }));
                        },

                        restoreDraft() {
                            try {
                                const d = JSON.parse(localStorage.getItem(this.draftKey));
                                $wire.set('form_subject', d.subject);
                                $wire.set('form_body',    d.body);
                            } catch(e) {}
                            this.clearDraft();
                        },

                        clearDraft() {
                            localStorage.removeItem(this.draftKey);
                            this.hasDraft  = false;
                            this.draftTime = null;
                        },

                        insertVar(v) {
                            const ta = $refs.bodyTextarea;
                            if (!ta) return;
                            const start = ta.selectionStart;
                            const end   = ta.selectionEnd;
                            const val   = ta.value;
                            const ins   = '{{' + v + '}}';
                            ta.value    = val.slice(0, start) + ins + val.slice(end);
                            ta.focus();
                            ta.selectionStart = ta.selectionEnd = start + ins.length;
                            $wire.set('form_body', ta.value);
                        },
                     }"
                     x-init="init()"
                     {{-- Clear draft from localStorage when Livewire confirms save --}}
                     @if(session('saved')) x-init="clearDraft()" @endif>

                    {{-- Draft restore banner --}}
                    <div x-show="hasDraft" x-cloak
                         class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800/40 dark:bg-amber-900/20">
                        <div class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                            <x-bootstrap-icon name="clock-history" class="size-4 shrink-0" />
                            <span>Osparade ändringar hittades
                                <span x-show="draftTime" class="text-amber-500">(<span x-text="draftTime"></span>)</span>.
                            </span>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" @click="clearDraft()"
                                    class="text-xs text-amber-500 hover:text-amber-700 dark:hover:text-amber-300">
                                Ignorera
                            </button>
                            <button type="button" @click="restoreDraft()"
                                    class="rounded bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-300">
                                Återställ utkast
                            </button>
                        </div>
                    </div>

                    {{-- Editor --}}
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                                {{ $this->selectedDef['label'] }}
                            </h2>
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" wire:model="form_active"
                                       class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                                <span class="text-xs text-zinc-600 dark:text-zinc-400">Aktiv</span>
                            </label>
                        </div>

                        <div class="grid gap-5 p-5 lg:grid-cols-[1fr_220px]">

                            {{-- Left: fields --}}
                            <div class="space-y-4">
                                {{-- Subject --}}
                                <div>
                                    <label class="lbl">Ämnesrad</label>
                                    <input wire:model.live.debounce.500ms="form_subject" type="text"
                                           class="inp mt-1 font-mono text-sm @error('form_subject') border-red-300 @enderror"
                                           placeholder="t.ex. Faktura – {{typ}}smedlemskap {{ar}}" />
                                    @error('form_subject')
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- Body --}}
                                <div>
                                    <label class="lbl">Brödtext</label>
                                    <p class="mt-0.5 mb-1.5 text-xs text-zinc-400 dark:text-zinc-500">
                                        Dubbelt radbryt = nytt stycke. Klicka på en variabel till höger för att infoga den.
                                    </p>
                                    <textarea
                                        x-ref="bodyTextarea"
                                        wire:model.live.debounce.500ms="form_body"
                                        rows="14"
                                        class="inp mt-1 font-mono text-sm leading-relaxed @error('form_body') border-red-300 @enderror"
                                        placeholder="Hej {{fornamn}}!&#10;&#10;Din faktura..."></textarea>
                                    @error('form_body')
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- Unknown variable warning (soft) --}}
                                @if(! empty($unknownVarsWarning))
                                    <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800/40 dark:bg-amber-900/20">
                                        <x-bootstrap-icon name="exclamation-triangle" class="mt-0.5 size-4 shrink-0 text-amber-500" />
                                        <div class="text-sm text-amber-700 dark:text-amber-400">
                                            <p class="font-medium">Okända variabler</p>
                                            <p class="mt-0.5 text-xs">
                                                Följande koder finns inte i mallens variabellista och kommer visas som klartext i mailet:
                                            </p>
                                            <p class="mt-1 font-mono text-xs">
                                                @foreach($unknownVarsWarning as $v)
                                                    @php($placeholder = '{{' . $v . '}}')
                                                    <span class="mr-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 dark:bg-amber-900/40">{{ $placeholder }}</span>
                                                @endforeach
                                            </p>
                                            <p class="mt-1 text-xs text-amber-500">Mallen sparades ändå. Kontrollera stavningen.</p>
                                        </div>
                                    </div>
                                @endif

                                {{-- Actions --}}
                                <div class="flex items-center justify-between">
                                    <button wire:click="resetToDefault"
                                            wire:confirm="Återställ till standardtexten? Dina ändringar skrivs över."
                                            class="text-xs text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                                        Återställ standardtext
                                    </button>
                                    <button wire:click="save" class="btn btn-primary">
                                        <x-bootstrap-icon name="floppy" class="size-4" />
                                        Spara mall
                                    </button>
                                </div>
                            </div>

                            {{-- Right: variable reference --}}
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                    Variabler
                                </p>
                                <p class="mb-3 text-xs text-zinc-400 dark:text-zinc-500">
                                    Klicka för att infoga i brödtexten.
                                </p>
                                <div class="space-y-1.5">
                                    @foreach($this->selectedDef['variables'] as $varName => $varDesc)
                                        @php($varPlaceholder = '{{' . $varName . '}}')
                                        <div>
                                            <button type="button"
                                                    @click="insertVar('{{ $varName }}')"
                                                    class="inline-flex items-center gap-1 rounded bg-zinc-100 px-2 py-1 font-mono text-xs font-medium text-zinc-700 transition-colors hover:bg-brand/20 hover:text-brand-dark dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-brand/20 dark:hover:text-brand">
                                                <x-bootstrap-icon name="braces" class="size-3" />
                                                {{ $varPlaceholder }}
                                            </button>
                                            <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $varDesc }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                        </div>
                    </div>

                    {{-- Test send --}}
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Skicka testmail</h2>
                        </div>
                        <div class="p-5">
                            <p class="mb-3 text-xs text-zinc-400 dark:text-zinc-500">
                                Skickar mallen med exempeldata så att du kan se hur den ser ut i en riktig e-postklient.
                                Spara mallen innan du testar så att det du ser är det senast sparade.
                            </p>
                            <div class="flex gap-2">
                                <input wire:model="testEmail" type="email"
                                       placeholder="din@epost.se"
                                       class="inp flex-1 @error('testEmail') border-red-300 @enderror" />
                                <button wire:click="sendTest" class="btn">
                                    <x-bootstrap-icon name="send" class="size-4" />
                                    Skicka
                                </button>
                            </div>
                            @error('testEmail')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @if($testResult)
                                <p class="mt-2 text-sm {{ $testOk ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $testResult }}
                                </p>
                            @endif
                        </div>
                    </div>

                </div>
            @else
                <div class="flex items-center justify-center rounded-xl border border-dashed border-zinc-200 py-16 dark:border-zinc-700">
                    <div class="text-center">
                        <x-bootstrap-icon name="envelope" class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Välj en mall för att redigera.</p>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
