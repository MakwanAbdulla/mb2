<?php

use App\Enums\QuestionType;
use App\Enums\SurveyStatus;
use App\Models\AuditLog;
use App\Models\Form\FormAnswer;
use App\Models\Form\FormSubmission;
use App\Models\Survey\Survey;
use App\Models\Survey\SurveyInvitation;
use App\Notifications\SurveyNewResponseNotification;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Enkät');

new class extends Component {
    public ?Survey           $survey     = null;
    public ?SurveyInvitation $invitation = null;
    public bool              $done       = false;
    public bool              $alreadyResponded = false;

    // answers: question_id => value (string for text, array for checkbox)
    public array $answers = [];

    public function mount(string $token): void
    {
        // Open survey via "open-{id}"
        if (str_starts_with($token, 'open-')) {
            $surveyId     = (int) substr($token, 5);
            $this->survey = Survey::with('questions.options')->find($surveyId);

            if (! $this->survey || ! $this->survey->is_open) {
                abort(404);
            }
        } else {
            $this->invitation = SurveyInvitation::with('survey.questions.options')
                ->where('token', $token)
                ->firstOrFail();
            $this->survey = $this->invitation->survey;

            if ($this->invitation->hasResponded()) {
                $this->alreadyResponded = true;
                return;
            }
        }

        if ($this->survey->status !== SurveyStatus::Active) {
            abort(404);
        }

        foreach ($this->survey->questions as $question) {
            $this->answers[$question->id] = $question->type === QuestionType::Checkbox ? [] : '';
        }
    }

    public function submit(): void
    {
        $rules = [];
        foreach ($this->survey->questions as $question) {
            if ($question->is_required) {
                $rules["answers.{$question->id}"] = $question->type === QuestionType::Checkbox
                    ? 'required|array|min:1'
                    : 'required|string|min:1';
            }
        }
        if (! empty($rules)) {
            $this->validate($rules);
        }

        $submission = FormSubmission::create([
            'form_id'          => $this->survey->form_id,
            'submittable_type' => Survey::class,
            'submittable_id'   => $this->survey->id,
            'invitation_id'    => $this->invitation?->id,
            'user_id'          => auth()->id(),
            'ip_address'       => request()->ip(),
        ]);

        foreach ($this->survey->questions as $question) {
            $value = $this->answers[$question->id] ?? null;

            if ($question->type === QuestionType::Checkbox && is_array($value)) {
                foreach ($value as $optionId) {
                    FormAnswer::create([
                        'submission_id' => $submission->id,
                        'question_id'   => $question->id,
                        'option_id'     => $optionId,
                        'answer_text'   => null,
                    ]);
                }
            } elseif ($question->type === QuestionType::Radio && $value) {
                FormAnswer::create([
                    'submission_id' => $submission->id,
                    'question_id'   => $question->id,
                    'option_id'     => $value,
                    'answer_text'   => null,
                ]);
            } elseif (! empty($value)) {
                FormAnswer::create([
                    'submission_id' => $submission->id,
                    'question_id'   => $question->id,
                    'option_id'     => null,
                    'answer_text'   => $value,
                ]);
            }
        }

        if ($this->invitation) {
            $this->invitation->update(['responded_at' => now()]);
        }

        if ($this->survey->notify_on_response) {
            $this->survey->creator?->notify(new SurveyNewResponseNotification($this->survey, $submission));
        }

        AuditLog::record(
            action: 'survey_response_submitted',
            area: 'surveys',
            subject: $this->survey->title,
            auditable: $this->survey,
        );

        $this->done = true;
    }
}; ?>

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-900">
    <div class="mx-auto max-w-2xl px-4 py-12 sm:px-6">

        @if($alreadyResponded)
            <div class="rounded-xl border border-zinc-200 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <x-bootstrap-icon name="check-circle" class="mx-auto mb-4 size-12 text-zinc-400" />
                <h2 class="mb-2 text-xl font-bold text-zinc-900 dark:text-white">Du har redan svarat</h2>
                <p class="text-zinc-500 dark:text-zinc-400">Tack! Du har redan lämnat ditt svar på denna enkät.</p>
            </div>

        @elseif($done)
            <div class="rounded-xl border border-green-200 bg-green-50 p-10 text-center dark:border-green-800 dark:bg-green-950">
                <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                    <x-bootstrap-icon name="check-lg" class="size-8 text-green-600 dark:text-green-400" />
                </div>
                <h2 class="mb-2 text-xl font-bold text-zinc-900 dark:text-white">Tack för ditt svar!</h2>
                <p class="text-zinc-500 dark:text-zinc-400">Ditt svar har registrerats.</p>
            </div>

        @else
            {{-- Enkäthuvud --}}
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $survey->title }}</h1>
                @if($survey->description)
                    <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ $survey->description }}</p>
                @endif
                @if($survey->is_anonymous)
                    <p class="mt-2 text-xs text-zinc-400">Denna enkät är anonym — dina svar kopplas inte till dig som person.</p>
                @endif
            </div>

            <form wire:submit="submit" class="space-y-6">
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
                            <input wire:model="answers.{{ $question->id }}" type="text" class="inp w-full" />

                        @elseif($question->type === QuestionType::Textarea)
                            <textarea wire:model="answers.{{ $question->id }}" rows="4" class="inp w-full resize-none"></textarea>

                        @elseif($question->type === QuestionType::Radio)
                            <div class="space-y-2">
                                @foreach($question->options as $option)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-2.5 transition-colors
                                        {{ isset($answers[$question->id]) && $answers[$question->id] == $option->id
                                            ? 'border-complement bg-complement/5 dark:border-complement-light dark:bg-complement/10'
                                            : 'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800' }}">
                                        <input type="radio" wire:model.live="answers.{{ $question->id }}"
                                               value="{{ $option->id }}" class="text-complement" />
                                        <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ $option->text }}</span>
                                    </label>
                                @endforeach
                            </div>

                        @elseif($question->type === QuestionType::Checkbox)
                            <div class="space-y-2">
                                @foreach($question->options as $option)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-2.5 transition-colors
                                        {{ in_array($option->id, $answers[$question->id] ?? [])
                                            ? 'border-complement bg-complement/5 dark:border-complement-light dark:bg-complement/10'
                                            : 'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800' }}">
                                        <input type="checkbox" wire:model.live="answers.{{ $question->id }}"
                                               value="{{ $option->id }}" class="rounded text-complement" />
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
                                    <button type="button"
                                            wire:click="$set('answers.{{ $question->id }}', '{{ $v }}')"
                                            class="flex size-12 items-center justify-center rounded-lg border-2 text-sm font-semibold transition-colors
                                                {{ isset($answers[$question->id]) && $answers[$question->id] == $v
                                                    ? 'border-brand bg-brand text-brand-dark'
                                                    : 'border-zinc-300 bg-white text-zinc-700 hover:border-brand dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200' }}">
                                        {{ $iconSymbol ?? $v }}
                                        @if($iconSymbol) <span class="sr-only">{{ $v }}</span> @endif
                                    </button>
                                @endfor
                            </div>
                            @if(isset($answers[$question->id]) && $answers[$question->id] !== '')
                                <p class="mt-2 text-xs text-zinc-400">Valt: {{ $answers[$question->id] }}</p>
                            @endif

                        @elseif($question->type === QuestionType::Datetime)
                            @php $variant = $question->datetime_variant ?? 'date'; @endphp
                            <input type="{{ $variant === 'date' ? 'date' : ($variant === 'time' ? 'time' : 'datetime-local') }}"
                                   wire:model="answers.{{ $question->id }}" class="inp w-full" />
                        @endif

                        @error("answers.{$question->id}")
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary">Skicka in svar</button>
                </div>
            </form>
        @endif
    </div>
</div>
