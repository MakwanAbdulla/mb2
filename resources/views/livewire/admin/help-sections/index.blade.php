<?php

use App\Models\AuditLog;
use App\Models\Content\HelpSection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Permission;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Hjälpsektioner');

new class extends Component {
    public bool  $creating         = false;
    public ?int  $editingId        = null;
    public ?int  $confirmingDelete = null;

    public string  $title              = '';
    public string  $slug               = '';
    public string  $groupLabel         = '';
    public string  $content            = '';
    public bool    $showPublic         = false;
    public bool    $showAuth           = false;
    public ?string $requiredPermission = null;
    public int     $sortOrder          = 0;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('help.manage'), 403);
    }

    public function updatedTitle(): void
    {
        if (! $this->editingId) {
            $this->slug = Str::slug($this->title);
        }
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->sortOrder = (HelpSection::max('sort_order') ?? 0) + 10;
        $this->creating  = true;
        $this->editingId = null;
    }

    public function edit(int $id): void
    {
        $section = HelpSection::findOrFail($id);

        $this->editingId          = $id;
        $this->creating           = false;
        $this->title              = $section->title;
        $this->slug               = $section->slug;
        $this->groupLabel         = $section->group_label ?? '';
        $this->content            = $section->content;
        $this->showPublic         = $section->show_public;
        $this->showAuth           = $section->show_auth;
        $this->requiredPermission = $section->required_permission;
        $this->sortOrder          = $section->sort_order;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('help.manage'), 403);

        $data = $this->validate([
            'title'      => ['required', 'string', 'max:255'],
            'slug'       => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::unique('help_sections', 'slug')->ignore($this->editingId)],
            'groupLabel' => ['nullable', 'string', 'max:100'],
            'content'    => ['required', 'string'],
            'showPublic'         => ['boolean'],
            'showAuth'           => ['boolean'],
            'requiredPermission' => ['nullable', 'string', 'max:100'],
            'sortOrder'          => ['required', 'integer', 'min:0', 'max:9999'],
        ], [], [
            'title'     => 'rubrik',
            'slug'      => 'slug',
            'content'   => 'innehåll',
            'sortOrder' => 'sorteringsordning',
        ]);

        $mapped = [
            'title'       => $data['title'],
            'slug'        => $data['slug'],
            'group_label' => $data['groupLabel'] ?: null,
            'content'     => $data['content'],
            'show_public'         => $data['showPublic'],
            'show_auth'           => $data['showAuth'],
            'required_permission' => $data['requiredPermission'] ?: null,
            'sort_order'          => $data['sortOrder'],
        ];

        if ($this->editingId) {
            $section = HelpSection::findOrFail($this->editingId);
            $section->fill($mapped);
            $changes = [];
            foreach ($section->getDirty() as $key => $new) {
                $changes[$key] = ['from' => (string) $section->getOriginal($key), 'to' => (string) $new];
            }
            $section->save();
            AuditLog::record('help_section_updated', 'help_sections', $this->title, $section, $changes ?: null);
        } else {
            $section = HelpSection::create($mapped);
            AuditLog::record('help_section_created', 'help_sections', $this->title, $section);
        }

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingId        = null;
        $this->creating         = false;
        $this->confirmingDelete = null;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDelete = $id;
    }

    public function delete(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('help.manage'), 403);

        $section = HelpSection::findOrFail($this->confirmingDelete);
        AuditLog::record('help_section_deleted', 'help_sections', $section->title);
        $section->delete();
        $this->confirmingDelete = null;
    }

    private function resetForm(): void
    {
        $this->title              = '';
        $this->slug               = '';
        $this->groupLabel         = '';
        $this->content            = '';
        $this->showPublic         = false;
        $this->showAuth           = false;
        $this->requiredPermission = null;
        $this->sortOrder          = 0;
    }

    public function with(): array
    {
        return [
            'sections'       => HelpSection::orderBy('sort_order')->get(),
            'existingGroups' => HelpSection::whereNotNull('group_label')->distinct()->pluck('group_label'),
            'permissions'    => Permission::orderBy('name')->get(['name', 'label']),
        ];
    }
};
?>

<div>
<div class="py-6">
<div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Hjälpsektioner</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('help') }}" wire:navigate target="_blank"
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                Visa hjälpsida →
            </a>
            @if (! $creating && ! $editingId)
                <button wire:click="startCreate" class="btn btn-primary">
                    + Ny sektion
                </button>
            @endif
        </div>
    </div>

    {{-- Flash-meddelande --}}
    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- Skapa ny --}}
    @if ($creating)
        <div class="mb-6 rounded-xl border border-brand/40 bg-white shadow-sm dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                <span class="font-semibold text-zinc-900 dark:text-white">Ny sektion</span>
            </div>
            @include('livewire.admin.help-sections._form')
        </div>
    @endif

    {{-- Lista --}}
    <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white shadow-sm dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">

        @forelse ($sections as $section)
            @if ($confirmingDelete === $section->id)
                <div class="flex items-center justify-between gap-4 bg-red-50 px-5 py-4 dark:bg-red-950/30">
                    <span class="text-sm text-red-700 dark:text-red-300">Ta bort <strong class="text-zinc-900 dark:text-white">{{ $section->title }}</strong>?</span>
                    <div class="flex shrink-0 gap-2">
                        <button wire:click="delete" class="btn btn-danger text-xs">Ja, ta bort</button>
                        <button wire:click="cancelEdit" class="btn text-xs">Avbryt</button>
                    </div>
                </div>

            @elseif ($editingId === $section->id)
                <div class="bg-zinc-50 dark:bg-zinc-750">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-600">
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">Redigerar: {{ $section->title }}</span>
                        <button wire:click="cancelEdit" class="text-xs text-zinc-400 transition-colors hover:text-zinc-700 dark:hover:text-white">Avbryt</button>
                    </div>
                    @include('livewire.admin.help-sections._form')
                </div>

            @else
                <div class="flex items-center gap-4 px-5 py-3.5">
                    <span class="w-10 shrink-0 text-right font-mono text-xs text-zinc-400">{{ $section->sort_order }}</span>
                    <div class="min-w-0 flex-1">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $section->title }}</span>
                        <span class="ml-2 font-mono text-xs text-zinc-400">#{{ $section->slug }}</span>
                        @if ($section->group_label)
                            <span class="ml-2 rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">{{ $section->group_label }}</span>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-1">
                        @if ($section->show_public)
                            <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">Publik</span>
                        @endif
                        @if ($section->show_auth)
                            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-900/50 dark:text-blue-300">Inloggad</span>
                        @endif
                        @if ($section->required_permission)
                            @php $permLabel = $permissions->firstWhere('name', $section->required_permission)?->label ?? $section->required_permission; @endphp
                            <span class="rounded-full bg-brand/20 px-2 py-0.5 text-xs text-brand-dark dark:bg-brand/10 dark:text-brand" title="{{ $section->required_permission }}">{{ $permLabel }}</span>
                        @endif
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button wire:click="edit({{ $section->id }})" class="btn text-xs">Redigera</button>
                        <button wire:click="confirmDelete({{ $section->id }})" class="btn btn-danger text-xs">Ta bort</button>
                    </div>
                </div>
            @endif
        @empty
            <div class="p-10 text-center text-zinc-500 dark:text-zinc-400">Inga sektioner ännu.</div>
        @endforelse

    </div>

</div>
</div>
</div>

<script>
(function () {
    document.addEventListener('trix-initialize', function (e) {
        const editor = e.target;
        if (editor.getAttribute('input') !== 'help-content-input') return;

        editor.addEventListener('trix-change', () => {
            @this.set('content', editor.value);
        });

        const toggleBtn = document.getElementById('helpToggleHtmlBtn');
        const codeArea  = document.getElementById('helpContentCode');
        const wrapper   = document.getElementById('helpTrixWrapper');
        if (!toggleBtn || !codeArea || !wrapper) return;
        let htmlMode = false;

        toggleBtn.addEventListener('click', () => {
            htmlMode = !htmlMode;
            if (htmlMode) {
                codeArea.value = editor.value || '';
                wrapper.style.display = 'none';
                codeArea.classList.remove('hidden');
                toggleBtn.textContent = 'Visuellt läge';
            } else {
                const html = codeArea.value || '';
                @this.set('content', html);
                editor.editor.loadHTML(html);
                codeArea.classList.add('hidden');
                wrapper.style.display = '';
                toggleBtn.textContent = 'HTML-läge';
            }
        });

        codeArea.addEventListener('input', () => {
            @this.set('content', codeArea.value);
        });
    });
})();
</script>
