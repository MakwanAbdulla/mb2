<?php

use App\Enums\QuestionType;
use App\Enums\SurveyStatus;
use App\Models\AuditLog;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormQuestionOption;
use App\Models\Survey\Survey;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Redigera enkät');

new class extends Component {
    public Survey $survey;

    public string $surveyTitle        = '';
    public string $description        = '';
    public bool   $is_anonymous       = false;
    public bool   $is_open            = false;
    public bool   $notify_on_response = false;
    public string $opens_at           = '';
    public string $closes_at          = '';
    public string $send_invitations_at = '';
    public array  $questions           = [];

    public function mount(Survey $survey): void
    {
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);

        $this->survey              = $survey;
        $this->surveyTitle         = $survey->title;
        $this->description         = $survey->description ?? '';
        $this->is_anonymous        = $survey->is_anonymous;
        $this->is_open             = $survey->is_open;
        $this->notify_on_response  = (bool) $survey->notify_on_response;
        $this->opens_at            = $survey->opens_at?->format('Y-m-d\TH:i') ?? '';
        $this->closes_at           = $survey->closes_at?->format('Y-m-d\TH:i') ?? '';
        $this->send_invitations_at = $survey->send_invitations_at?->format('Y-m-d\TH:i') ?? '';

        $this->questions = $survey->questions->map(function (FormQuestion $q) {
            return [
                '_id'              => $q->id,
                'type'             => $q->type->value,
                'label'            => $q->label,
                'description'      => $q->description ?? '',
                'is_required'      => $q->is_required,
                'scale_min'        => $q->scale_min ?? 1,
                'scale_max'        => $q->scale_max ?? 5,
                'scale_icon'       => $q->scale_icon ?? 'star',
                'datetime_variant' => $q->datetime_variant ?? 'date',
                'number_min'       => $q->number_min ?? '',
                'number_max'       => $q->number_max ?? '',
                'product_id'       => $q->product_id ?? '',
                'event_ids'        => $q->event_ids ?? [],
                'options'          => $q->options->map(fn ($o) => ['_id' => $o->id, 'text' => $o->text])->toArray(),
            ];
        })->toArray();
    }

    public function addQuestion(): void
    {
        $this->questions[] = [
            '_id'              => null,
            'type'             => QuestionType::Text->value,
            'label'            => '',
            'description'      => '',
            'is_required'      => false,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'number_min'       => '',
            'number_max'       => '',
            'product_id'       => '',
            'event_ids'        => [],
            'options'          => [],
        ];
    }

    public function removeQuestion(int $index): void
    {
        $questionId = $this->questions[$index]['_id'] ?? null;
        if ($questionId) {
            FormQuestion::destroy($questionId);
        }
        array_splice($this->questions, $index, 1);
    }

    public function moveUp(int $index): void
    {
        if ($index === 0) {
            return;
        }
        [$this->questions[$index - 1], $this->questions[$index]] = [$this->questions[$index], $this->questions[$index - 1]];
        $this->questions = array_values($this->questions);
    }

    public function moveDown(int $index): void
    {
        if ($index >= count($this->questions) - 1) {
            return;
        }
        [$this->questions[$index], $this->questions[$index + 1]] = [$this->questions[$index + 1], $this->questions[$index]];
        $this->questions = array_values($this->questions);
    }

    public function addOption(int $questionIndex): void
    {
        $this->questions[$questionIndex]['options'][] = ['_id' => null, 'text' => ''];
    }

    public function removeOption(int $questionIndex, int $optionIndex): void
    {
        $optionId = $this->questions[$questionIndex]['options'][$optionIndex]['_id'] ?? null;
        if ($optionId) {
            FormQuestionOption::destroy($optionId);
        }
        array_splice($this->questions[$questionIndex]['options'], $optionIndex, 1);
    }

    public function save(): void
    {
        $this->validate([
            'surveyTitle'         => 'required|string|max:255',
            'description'         => 'nullable|string',
            'opens_at'            => 'nullable|date',
            'closes_at'           => ['nullable', 'date', $this->opens_at ? 'after:opens_at' : 'after:now'],
            'send_invitations_at' => 'nullable|date',
            'questions'           => 'array|min:1',
            'questions.*.label'   => 'required|string|max:500',
            'questions.*.type'    => 'required|string|in:' . implode(',', array_column(QuestionType::cases(), 'value')),
        ]);

        $this->survey->update([
            'title'               => $this->surveyTitle,
            'description'         => $this->description ?: null,
            'is_anonymous'        => $this->is_anonymous,
            'is_open'             => $this->is_open,
            'notify_on_response'  => $this->notify_on_response,
            'opens_at'            => $this->opens_at ?: null,
            'closes_at'           => $this->closes_at ?: null,
            'send_invitations_at' => $this->send_invitations_at ?: null,
        ]);

        $form    = $this->survey->form;
        $keptIds = [];

        foreach ($this->questions as $i => $q) {
            $type = QuestionType::from($q['type']);
            $data = [
                'type'             => $type,
                'label'            => $q['label'],
                'description'      => $q['description'] ?: null,
                'is_required'      => $q['is_required'] ?? false,
                'sort_order'       => $i,
                'scale_min'        => $type->isScale() ? (int) $q['scale_min'] : null,
                'scale_max'        => $type->isScale() ? (int) $q['scale_max'] : null,
                'scale_icon'       => $type->isScale() ? $q['scale_icon'] : null,
                'datetime_variant' => $type->isDatetime() ? $q['datetime_variant'] : null,
                'number_min'       => $type === QuestionType::Number && $q['number_min'] !== '' ? (float) $q['number_min'] : null,
                'number_max'       => $type === QuestionType::Number && $q['number_max'] !== '' ? (float) $q['number_max'] : null,
                'product_id'       => $type->isProduct() && ! empty($q['product_id']) ? (int) $q['product_id'] : null,
                'event_ids'        => $type->isEventSelection() && ! empty($q['event_ids']) ? array_map('intval', $q['event_ids']) : null,
            ];

            if (! empty($q['_id'])) {
                $question = FormQuestion::find($q['_id']);
                $question?->update($data);
                $keptIds[] = $q['_id'];
            } else {
                $question  = $form->questions()->create($data);
                $keptIds[] = $question->id;
            }

            if ($type->hasOptions() && $question) {
                $keptOptIds = [];

                foreach (($q['options'] ?? []) as $j => $opt) {
                    if (trim($opt['text']) === '') {
                        continue;
                    }
                    if (! empty($opt['_id'])) {
                        FormQuestionOption::where('id', $opt['_id'])->update(['text' => $opt['text'], 'sort_order' => $j]);
                        $keptOptIds[] = $opt['_id'];
                    } else {
                        $newOpt       = $question->options()->create(['text' => $opt['text'], 'sort_order' => $j]);
                        $keptOptIds[] = $newOpt->id;
                    }
                }

                $question->options()->whereNotIn('id', $keptOptIds)->delete();
            }
        }

        $form->questions()->whereNotIn('id', $keptIds)->each(function ($q) {
            $q->options()->delete();
            $q->delete();
        });

        AuditLog::record(
            action: 'survey_updated',
            area: 'surveys',
            subject: $this->survey->title,
            auditable: $this->survey,
        );

        $this->dispatch('notify', message: 'Enkät sparad');
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.surveys.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Tillbaka</a>
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Redigera: {{ $survey->title }}</h1>
        @php
            $statusColors = ['draft' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300', 'active' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'closed' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'];
        @endphp
        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusColors[$survey->status->value] ?? '' }}">
            {{ $survey->status->label() }}
        </span>
        <div class="ml-auto flex gap-2">
            <a href="{{ route('admin.surveys.invitations', $survey) }}" wire:navigate class="btn btn-ghost btn-sm">Inbjudningar</a>
            @if($survey->submissions()->exists())
                <a href="{{ route('admin.surveys.responses', $survey) }}" wire:navigate class="btn btn-ghost btn-sm">
                    Svar ({{ $survey->submissions()->count() }})
                </a>
            @endif
            <a href="{{ route('admin.surveys.preview', $survey) }}" wire:navigate class="btn btn-ghost btn-sm">Förhandsgranska</a>
            @if($survey->is_open)
                <button x-data @click="navigator.clipboard.writeText('{{ route('surveys.respond', 'open-'.$survey->id) }}')"
                        class="btn btn-ghost btn-sm">Kopiera öppen länk</button>
            @endif
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">

        {{-- Enkätinformation --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Enkätinformation</span>
            </div>
            <div class="space-y-4 px-5 py-4">
                <div>
                    <label class="lbl">Titel *</label>
                    <input wire:model="surveyTitle" type="text" class="inp w-full" />
                    @error('surveyTitle') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Beskrivning</label>
                    <textarea wire:model="description" rows="3" class="inp w-full resize-none"></textarea>
                </div>
                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm">
                        <input wire:model="is_anonymous" type="checkbox" class="rounded" />
                        <span class="text-zinc-700 dark:text-zinc-300">Anonym enkät</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input wire:model="is_open" type="checkbox" class="rounded" />
                        <span class="text-zinc-700 dark:text-zinc-300">Öppen enkät</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input wire:model="notify_on_response" type="checkbox" class="rounded" />
                        <span class="text-zinc-700 dark:text-zinc-300">Notifiera vid svar</span>
                    </label>
                </div>
                <div class="border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Schemaläggning (valfritt)</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label class="lbl">Öppnar</label>
                            <input wire:model="opens_at" type="datetime-local" class="inp w-full" />
                        </div>
                        <div>
                            <label class="lbl">Stänger</label>
                            <input wire:model="closes_at" type="datetime-local" class="inp w-full" />
                        </div>
                        <div>
                            <label class="lbl">Skicka inbjudningar</label>
                            <input wire:model="send_invitations_at" type="datetime-local" class="inp w-full" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Frågor --}}
        <div class="space-y-4">
            @foreach($questions as $i => $question)
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Fråga {{ $i + 1 }}</span>
                        <div class="flex items-center gap-1">
                            <x-sort-buttons :id="$i" :index="$i" :count="count($questions)" />
                            <button type="button" wire:click="removeQuestion({{ $i }})"
                                    class="rounded px-2 py-0.5 text-red-400 hover:text-red-600">✕</button>
                        </div>
                    </div>
                    <div class="space-y-4 px-5 py-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Frågetext *</label>
                                <input wire:model="questions.{{ $i }}.label" type="text" class="inp w-full" />
                                @error("questions.{$i}.label") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Typ</label>
                                <select wire:model.live="questions.{{ $i }}.type" class="inp w-full">
                                    @foreach(QuestionType::cases() as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="lbl">Hjälptext</label>
                            <input wire:model="questions.{{ $i }}.description" type="text" placeholder="Valfri förklaringstext" class="inp w-full" />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input wire:model="questions.{{ $i }}.is_required" type="checkbox" class="rounded" />
                            <span class="text-zinc-700 dark:text-zinc-300">Obligatorisk fråga</span>
                        </label>

                        @if($question['type'] === 'scale')
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <div class="grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <label class="lbl">Min</label>
                                        <input wire:model="questions.{{ $i }}.scale_min" type="number" min="0" max="10" class="inp w-full" />
                                    </div>
                                    <div>
                                        <label class="lbl">Max</label>
                                        <input wire:model="questions.{{ $i }}.scale_max" type="number" min="1" max="10" class="inp w-full" />
                                    </div>
                                    <div>
                                        <label class="lbl">Ikon</label>
                                        <select wire:model="questions.{{ $i }}.scale_icon" class="inp w-full">
                                            <option value="star">⭐ Stjärna</option>
                                            <option value="heart">❤️ Hjärta</option>
                                            <option value="thumb">👍 Tumme</option>
                                            <option value="number">🔢 Siffra</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($question['type'] === 'datetime')
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <label class="lbl">Variant</label>
                                <select wire:model="questions.{{ $i }}.datetime_variant" class="inp w-full">
                                    <option value="date">Datum</option>
                                    <option value="time">Tid</option>
                                    <option value="datetime">Datum & tid</option>
                                </select>
                            </div>
                        @endif

                        @if(in_array($question['type'], ['radio', 'checkbox', 'select']))
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <label class="lbl mb-2">Svarsalternativ</label>
                                <div class="space-y-2">
                                    @foreach($question['options'] as $j => $opt)
                                        <div class="flex items-center gap-2">
                                            <input wire:model="questions.{{ $i }}.options.{{ $j }}.text"
                                                   type="text" placeholder="Alternativ {{ $j + 1 }}" class="inp flex-1" />
                                            <button type="button" wire:click="removeOption({{ $i }}, {{ $j }})"
                                                    class="text-red-400 hover:text-red-600">✕</button>
                                        </div>
                                    @endforeach
                                </div>
                                <button type="button" wire:click="addOption({{ $i }})"
                                        class="btn btn-ghost btn-sm mt-2">+ Lägg till alternativ</button>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            <button type="button" wire:click="addQuestion"
                    class="w-full rounded-xl border border-dashed border-zinc-300 py-3 text-sm text-zinc-500 hover:border-zinc-400 hover:text-zinc-700 dark:border-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-200">
                + Lägg till fråga
            </button>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.surveys.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
            <button type="submit" class="btn btn-primary">Spara ändringar</button>
        </div>
    </form>
</div>
