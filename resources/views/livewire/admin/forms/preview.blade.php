<?php

use App\Models\Form\Form;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Förhandsgranska formulär');

new class extends Component {
    public Form $form;

    public function mount(Form $form): void
    {
        abort_unless(Auth::user()->hasPermissionTo('forms.manage'), 403);
        $this->form = $form->load('questions.options', 'questions.product.variants');
    }
};

?>

<div>
    <x-page-header title="Förhandsgranskning: {{ $form->title }}">
        <x-slot name="back">
            <a href="{{ route('admin.forms.edit', $form) }}" wire:navigate class="btn btn-ghost btn-sm">
                <x-bootstrap-icon name="arrow-left" class="size-4" />
                Tillbaka
            </a>
        </x-slot>
    </x-page-header>

    <div class="mx-auto max-w-2xl">
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/30 dark:bg-amber-900/20 dark:text-amber-200">
            <x-bootstrap-icon name="eye" class="mr-1 inline size-4" />
            Detta är en förhandsgranskning. Formuläret kan inte skickas in härifrån.
        </div>

        @include('livewire.pages.forms._form-display', ['form' => $form, 'preview' => true])
    </div>
</div>
