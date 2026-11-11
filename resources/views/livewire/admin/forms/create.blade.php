<?php

use App\Models\AuditLog;
use App\Models\Form\Form;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Nytt formulär');

new class extends Component {

    public string $formTitle   = '';
    public string $description = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('forms.manage'), 403);
    }

    public function save(): void
    {
        $this->validate([
            'formTitle'   => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $form = Form::create([
            'title'       => $this->formTitle,
            'description' => $this->description ?: null,
            'created_by'  => Auth::id(),
        ]);

        AuditLog::record(
            action: 'form_created',
            area: 'forms',
            subject: $form->title,
            auditable: $form,
        );

        $this->redirectRoute('admin.forms.edit', $form, navigate: true);
    }
};

?>

<div>
    <x-page-header title="Nytt formulär">
        <x-slot name="back">
            <a href="{{ route('admin.forms.index') }}" wire:navigate class="btn btn-ghost btn-sm">
                <x-bootstrap-icon name="arrow-left" class="size-4" />
                Tillbaka
            </a>
        </x-slot>
    </x-page-header>

    <form wire:submit="save" class="mx-auto max-w-xl space-y-5">
        <div>
            <label for="formTitle" class="lbl">Titel</label>
            <input id="formTitle" type="text" wire:model="formTitle" class="inp" autofocus />
            @error('formTitle') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="description" class="lbl">Beskrivning <span class="font-normal text-zinc-400">(valfri)</span></label>
            <textarea id="description" wire:model="description" rows="3" class="inp"></textarea>
            @error('description') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn btn-primary">Skapa formulär</button>
            <a href="{{ route('admin.forms.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
        </div>
    </form>
</div>
