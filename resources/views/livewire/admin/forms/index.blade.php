<?php

use App\Models\AuditLog;
use App\Models\Form\Form;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Formulär');

new class extends Component {

    #[Computed]
    public function forms()
    {
        abort_unless(Auth::user()->hasPermissionTo('forms.manage'), 403);

        return Form::withCount(['questions', 'submissions'])
            ->with('creator')
            ->latest()
            ->get();
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('forms.manage'), 403);
    }

    public function delete(int $id): void
    {
        $form  = Form::findOrFail($id);
        $title = $form->title;
        $form->delete();

        AuditLog::record(
            action: 'form_deleted',
            area: 'forms',
            subject: $title,
        );

        unset($this->forms);
    }
};

?>

<div>
    <x-page-header title="Formulär">
        <x-slot name="actions">
            <a href="{{ route('admin.forms.create') }}" wire:navigate class="btn btn-primary">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Nytt formulär
            </a>
        </x-slot>
    </x-page-header>

    @if($this->forms->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="ui-checks" class="mx-auto mb-4 size-12 text-zinc-400" />
            <p class="mb-1 font-medium text-zinc-700 dark:text-zinc-300">Inga formulär än</p>
            <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Skapa ditt första formulär för att börja samla in svar.</p>
            <a href="{{ route('admin.forms.create') }}" wire:navigate class="btn btn-primary btn-sm">Skapa formulär</a>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                        <th class="px-4 py-3">Titel</th>
                        <th class="px-4 py-3 text-center">Frågor</th>
                        <th class="px-4 py-3 text-center">Svar</th>
                        <th class="px-4 py-3">Skapad av</th>
                        <th class="px-4 py-3">Skapad</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach($this->forms as $form)
                        <tr class="bg-white transition hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                            <td class="px-4 py-3 font-medium text-zinc-800 dark:text-zinc-200">
                                <a href="{{ route('admin.forms.edit', $form) }}" wire:navigate class="hover:underline">
                                    {{ $form->title }}
                                </a>
                                @if($form->description)
                                    <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500 line-clamp-1">{{ $form->description }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-zinc-600 dark:text-zinc-400">{{ $form->questions_count }}</td>
                            <td class="px-4 py-3 text-center text-zinc-600 dark:text-zinc-400">
                                @if($form->submissions_count > 0)
                                    <a href="{{ route('admin.forms.responses', $form) }}" wire:navigate class="text-complement hover:underline dark:text-complement-light">
                                        {{ $form->submissions_count }}
                                    </a>
                                @else
                                    <span class="text-zinc-400">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $form->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ $form->created_at->format('d/m Y') }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.forms.preview', $form) }}" wire:navigate
                                       class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="Förhandsgranska">
                                        <x-bootstrap-icon name="eye" class="size-4" />
                                    </a>
                                    <a href="{{ route('admin.forms.edit', $form) }}" wire:navigate
                                       class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="Redigera">
                                        <x-bootstrap-icon name="pencil" class="size-4" />
                                    </a>
                                    <button wire:click="delete({{ $form->id }})"
                                            wire:confirm="Ta bort formuläret '{{ $form->title }}'? Alla sparade svar tas bort."
                                            class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400" title="Ta bort">
                                        <x-bootstrap-icon name="trash" class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
