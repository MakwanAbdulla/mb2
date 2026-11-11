<?php

use App\Enums\QuestionType;
use App\Models\Survey\Survey;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Förhandsgranska enkät');

new class extends Component {
    public Survey $survey;

    public function mount(Survey $survey): void
    {
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);
        $this->survey = $survey->load('questions.options');
    }
}; ?>

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6">

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.surveys.edit', $survey) }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Tillbaka till redigera</a>
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Förhandsgranska: {{ $survey->title }}</h1>
        <span class="rounded-full bg-brand/20 px-2.5 py-0.5 text-xs font-medium text-brand-dark dark:text-brand">Förhandsvisning</span>
    </div>

    <div class="mb-6">
        <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $survey->title }}</h2>
        @if($survey->description)
            <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ $survey->description }}</p>
        @endif
        @if($survey->is_anonymous)
            <p class="mt-2 text-xs text-zinc-400">Denna enkät är anonym — dina svar kopplas inte till dig som person.</p>
        @endif
    </div>

    <div class="space-y-6">
        @foreach($survey->questions as $question)
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3">
                    <p class="font-semibold text-zinc-900 dark:text-white">
                        {{ $question->label }}
                        @if($question->is_required)
                            <span class="ml-1 text-red-500">*</span>
                        @endif
                    </p>
                    @if($question->description)
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                    @endif
                </div>

                @if($question->type === QuestionType::Text)
                    <input type="text" disabled placeholder="Fritextsvar…" class="inp w-full opacity-60" />

                @elseif($question->type === QuestionType::Textarea)
                    <textarea rows="4" disabled placeholder="Fritextsvar…" class="inp w-full resize-none opacity-60"></textarea>

                @elseif($question->type === QuestionType::Radio)
                    <div class="space-y-2">
                        @foreach($question->options as $option)
                            <label class="flex cursor-not-allowed items-center gap-3 rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-600">
                                <input type="radio" disabled class="text-complement" />
                                <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ $option->text }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($question->type === QuestionType::Checkbox)
                    <div class="space-y-2">
                        @foreach($question->options as $option)
                            <label class="flex cursor-not-allowed items-center gap-3 rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-600">
                                <input type="checkbox" disabled class="rounded text-complement" />
                                <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ $option->text }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif($question->type === QuestionType::Scale)
                    @php
                        $min        = $question->scale_min ?? 1;
                        $max        = $question->scale_max ?? 5;
                        $icon       = $question->scale_icon ?? 'number';
                        $iconSymbol = match($icon) { 'star' => '★', 'heart' => '♥', 'thumb' => '👍', default => null };
                    @endphp
                    <div class="mt-2 flex flex-wrap gap-2">
                        @for($v = $min; $v <= $max; $v++)
                            <div class="flex size-12 cursor-not-allowed items-center justify-center rounded-lg border-2 border-zinc-300 bg-white text-sm font-semibold text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200">
                                {{ $iconSymbol ?? $v }}
                            </div>
                        @endfor
                    </div>

                @elseif($question->type === QuestionType::Datetime)
                    @php $variant = $question->datetime_variant ?? 'date'; @endphp
                    <input type="{{ $variant === 'date' ? 'date' : ($variant === 'time' ? 'time' : 'datetime-local') }}"
                           disabled class="inp w-full opacity-60" />
                @endif
            </div>
        @endforeach

        <div class="flex justify-end">
            <button disabled class="btn btn-primary opacity-50 cursor-not-allowed">Skicka in svar</button>
        </div>
    </div>
</div>
