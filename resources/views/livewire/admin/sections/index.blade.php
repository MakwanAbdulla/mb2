<?php

use App\Models\AuditLog;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('sections.manage'), 403);
    }

    public function deleteSection(int $id): void
    {
        $section = Section::findOrFail($id);

        if ($section->children()->exists() || $section->activeMembers()->exists()) {
            $this->addError('delete', 'Kan inte ta bort en grupp med undergrupper eller aktiva medlemmar.');
            return;
        }

        if ($section->hasWikiPages()) {
            $this->addError('delete', "Gruppen {$section->name} har sidor i wikin och kan inte tas bort. Inaktivera gruppen i stället, så finns kunskapen kvar.");
            return;
        }

        $sectionName = $section->name;
        $section->delete();
        AuditLog::record('section_deleted', 'sections', $sectionName, null);
    }

    public function toggleActive(int $id): void
    {
        $section = Section::findOrFail($id);
        $section->update(['is_active' => ! $section->is_active]);
        AuditLog::record('section_toggled_active', 'sections', $section->name, $section);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="sections.manage" title="Sektioner & grupper" subtitle="Föreningens sektioner och deras undergrupper">
        <a href="{{ route('admin.sections.create') }}" wire:navigate
           class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
            <x-bootstrap-icon name="plus-lg" class="size-4" />
            Ny sektion
        </a>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">

    @error('delete')
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror

    @php
        $roots = Section::with(['children.children', 'activity', 'tags'])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $groupsCanHaveSubgroups = \App\Models\SystemSetting::get('sections.groups_can_have_subgroups', true);
    @endphp

    @if($roots->isEmpty())
        <div class="py-16 text-center">
            <x-bootstrap-icon name="diagram-3" class="mx-auto mb-3 size-12 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Inga sektioner skapade ännu.</p>
            <a href="{{ route('admin.sections.create') }}" wire:navigate
               class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Skapa första sektionen
            </a>
        </div>
    @else
        <div class="space-y-1">
            @foreach($roots as $root)
                @include('livewire.admin.sections._row', ['section' => $root, 'depth' => 0, 'groupsCanHaveSubgroups' => $groupsCanHaveSubgroups])
            @endforeach
        </div>
    @endif
</div>
