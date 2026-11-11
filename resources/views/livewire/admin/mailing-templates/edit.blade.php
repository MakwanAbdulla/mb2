<?php

use App\Models\AuditLog;
use App\Models\Content\MailingTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Redigera utskicksmall');

new class extends Component {

    public MailingTemplate $template;

    public string  $name               = '';
    public string  $category           = '';
    public string  $description        = '';
    public string  $subject            = '';
    public string  $bodyHtml           = '';
    public ?string $permissionRequired = null;

    public function mount(MailingTemplate $template): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);

        $this->template          = $template;
        $this->name              = $template->name;
        $this->category          = $template->category ?? '';
        $this->description       = $template->description ?? '';
        $this->subject           = $template->subject;
        $this->bodyHtml          = $template->body_html;
        $this->permissionRequired = $template->permission_required;
    }

    public function save(): void
    {
        $this->validate([
            'name'               => 'required|string|max:255',
            'category'           => 'nullable|string',
            'subject'            => 'required|string|max:255',
            'bodyHtml'           => 'required|string',
            'permissionRequired' => 'nullable|string|max:100',
        ], [
            'name.required'    => 'Namn krävs.',
            'subject.required' => 'Ämne krävs.',
            'bodyHtml.required'=> 'Brödtext krävs.',
        ]);

        $this->template->update([
            'name'                => $this->name,
            'category'            => $this->category ?: null,
            'description'         => $this->description,
            'subject'             => $this->subject,
            'body_html'           => $this->bodyHtml,
            'permission_required' => $this->permissionRequired ?: null,
        ]);

        AuditLog::record(action: 'mailing_template_updated', area: 'mailings', subject: $this->template->name, auditable: $this->template);

        $this->redirect(route('admin.mailing-templates.index'), navigate: true);
    }
}; ?>

<div class="mx-auto max-w-4xl">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.mailing-templates.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-5" />
        </a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Redigera utskicksmall</h1>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="space-y-5 lg:col-span-2">

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
                <div>
                    <label class="lbl">Mallnamn</label>
                    <input wire:model="name" type="text" class="inp w-full" placeholder="T.ex. Nyhetsbrev — standard" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Kategori <span class="font-normal text-zinc-400">valfritt</span></label>
                    <select wire:model="category" class="inp w-full">
                        <option value="">— Ingen kategori —</option>
                        @foreach(\App\Support\NotificationCategory::forSelect() as $slug => $label)
                            <option value="{{ $slug }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-zinc-400">Används som standardkategori när du skapar ett utskick från den här mallen.</p>
                </div>
                <div>
                    <label class="lbl">Beskrivning <span class="font-normal text-zinc-400">valfritt</span></label>
                    <input wire:model="description" type="text" class="inp w-full" placeholder="Kort beskrivning av när mallen används" />
                </div>
                <div>
                    <label class="lbl">Behörighet <span class="font-normal text-zinc-400">valfritt — lämna tomt för alla med utskicksbehörighet</span></label>
                    <input wire:model="permissionRequired" type="text" class="inp w-full font-mono text-sm"
                           placeholder="t.ex. events.manage-all, sections.manage" />
                    @error('permissionRequired') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
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
                <a href="{{ route('admin.mailing-templates.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
                <button type="submit" class="btn btn-primary">Spara ändringar</button>
            </div>

        </form>

        <aside class="space-y-4">
            <x-mailing-variable-list />
        </aside>
    </div>
</div>

<script>
    document.addEventListener('trix-file-accept', e => e.preventDefault());

    document.addEventListener('livewire:navigated', () => {
        const input = document.getElementById('body-input');
        const editor = document.querySelector('trix-editor');
        if (input && editor && editor.editor && input.value) {
            editor.editor.loadHTML(input.value);
        }
    });
</script>
