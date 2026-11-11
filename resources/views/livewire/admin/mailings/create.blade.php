<?php

use App\Models\AuditLog;
use App\Models\Content\Mailing;
use App\Models\Content\MailingTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Nytt utskick');

new class extends Component {

    public string $title          = '';
    public string $category       = '';
    public string $subject        = '';
    public string $bodyHtml       = '';
    public ?int   $templateId     = null;

    /** @var \Illuminate\Database\Eloquent\Collection */
    public $availableTemplates;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);

        $user = Auth::user();
        $this->availableTemplates = MailingTemplate::all()
            ->filter(fn ($t) => $t->isAvailableTo($user))
            ->values();
    }

    public function updatedTemplateId(?int $value): void
    {
        if (! $value) {
            return;
        }
        $template = MailingTemplate::find($value);
        if ($template && $template->isAvailableTo(Auth::user())) {
            $this->subject  = $template->subject;
            $this->bodyHtml = $template->body_html;
            if ($template->category) {
                $this->category = $template->category;
            }
            $this->dispatch('load-trix-content', html: $template->body_html);
        }
    }

    public function save(): void
    {
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

        $mailing = Mailing::create([
            'title'               => $this->title,
            'category'            => $this->category ?: null,
            'subject'             => $this->subject,
            'body_html'           => $this->bodyHtml,
            'mailing_template_id' => $this->templateId ?: null,
            'status'              => Mailing::STATUS_DRAFT,
            'created_by'          => Auth::id(),
        ]);

        AuditLog::record(action: 'mailing_created', area: 'mailings', subject: $mailing->title, auditable: $mailing);

        $this->redirect(route('admin.mailings.edit', $mailing), navigate: true);
    }
}; ?>

<div class="mx-auto max-w-4xl">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.mailings.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-5" />
        </a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Nytt utskick</h1>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="space-y-5 lg:col-span-2">

            @if($availableTemplates->isNotEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <label class="lbl">Utgå från mall <span class="font-normal text-zinc-400">valfritt</span></label>
                    <select wire:model.live="templateId" class="inp w-full">
                        <option value="">— Börja från grunden —</option>
                        @foreach($availableTemplates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-zinc-400">Väljer du en mall kopieras ämne och brödtext hit. Du kan sedan redigera fritt.</p>
                </div>
            @endif

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
                <div>
                    <label class="lbl">Intern titel</label>
                    <input wire:model="title" type="text" class="inp w-full"
                           placeholder="T.ex. Nyhetsbrev april 2026" />
                    <p class="mt-1 text-xs text-zinc-400">Visas bara för administratörer, inte för mottagarna.</p>
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Kategori <span class="font-normal text-zinc-400">valfritt</span></label>
                    <select wire:model="category" class="inp w-full">
                        <option value="">— Ingen kategori —</option>
                        @foreach(\App\Support\NotificationCategory::forSelect() as $slug => $label)
                            <option value="{{ $slug }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-zinc-400">Mottagare som avprenumererat från kategorin hoppas över vid sändning.</p>
                </div>
                <div>
                    <label class="lbl">Ämnesrad</label>
                    <input wire:model="subject" type="text" class="inp w-full"
                           placeholder="Hej {{fornamn}}, nytt från Armagedon!" />
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
                                     data-blob-url-template=""></trix-editor>
                    </div>
                    @error('bodyHtml') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('admin.mailings.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
                <button type="submit" class="btn btn-primary">Skapa och lägg till mottagare →</button>
            </div>

        </form>

        <aside class="space-y-4">
            <x-mailing-variable-list />
        </aside>
    </div>
</div>

<script>
    document.addEventListener('trix-file-accept', e => e.preventDefault());

    document.addEventListener('livewire:init', () => {
        Livewire.on('load-trix-content', ({ html }) => {
            const editor = document.querySelector('trix-editor');
            if (editor?.editor) {
                editor.editor.loadHTML(html);
            }
        });
    });
</script>
