<?php

use App\Models\AuditLog;
use App\Models\Content\MailingTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Ny utskicksmall');

new class extends Component {

    public string  $name                = '';
    public string  $category            = '';
    public string  $description         = '';
    public string  $subject             = '';
    public string  $bodyHtml            = '';
    public ?string $permissionRequired  = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);
    }

    public function save(): void
    {
        $this->validate([
            'name'    => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'bodyHtml'=> 'required|string',
            'permissionRequired' => 'nullable|string|max:100',
        ], [
            'name.required'    => 'Namn krävs.',
            'subject.required' => 'Ämne krävs.',
            'bodyHtml.required'=> 'Brödtext krävs.',
        ]);

        $template = MailingTemplate::create([
            'name'                => $this->name,
            'category'            => $this->category ?: null,
            'description'         => $this->description,
            'subject'             => $this->subject,
            'body_html'           => $this->bodyHtml,
            'permission_required' => $this->permissionRequired ?: null,
            'created_by'          => Auth::id(),
        ]);

        AuditLog::record(action: 'mailing_template_created', area: 'mailings', subject: $template->name, auditable: $template);

        $this->redirect(route('admin.mailing-templates.index'), navigate: true);
    }
}; ?>

<div class="mx-auto max-w-4xl">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.mailing-templates.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-5" />
        </a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Ny utskicksmall</h1>
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
                <button type="submit" class="btn btn-primary">Spara mall</button>
            </div>

        </form>

        <aside class="space-y-4">
            <x-mailing-variable-list />
        </aside>
    </div>
</div>

<script>
    // Disable Trix file attachments — not email-safe
    document.addEventListener('trix-file-accept', e => e.preventDefault());
</script>
