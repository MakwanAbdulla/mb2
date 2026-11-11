<?php

use App\Enums\QuestionType;
use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Form\Form;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormQuestionOption;
use App\Models\Organization\Section;
use App\Models\Rental\RentalItemType;
use App\Models\Shop\Product;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Redigera formulär');

new class extends Component {
    public Form $form;

    public string $formTitle   = '';
    public string $description = '';
    public array  $questions   = [];

    public function mount(Form $form): void
    {
        abort_unless(Auth::user()->hasPermissionTo('forms.manage'), 403);

        $this->form        = $form;
        $this->formTitle   = $form->title;
        $this->description = $form->description ?? '';

        $this->questions = $form->questions->map(function (FormQuestion $q) {
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
                'product_id'          => $q->product_id ?? '',
                'event_ids'           => $q->event_ids ?? [],
                'section_ids'         => $q->section_ids ?? [],
                'section_multi'       => $q->section_multi ?? false,
                'membership_type'     => $q->membership_type ?? '',
                'rental_item_type_id' => $q->rental_item_type_id ?? '',
                'options'             => $q->options->map(fn ($o) => ['_id' => $o->id, 'text' => $o->text])->toArray(),
            ];
        })->toArray();
    }

    #[Computed]
    public function products()
    {
        return Product::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function events()
    {
        return Event::where('status', Event::STATUS_PUBLISHED)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    #[Computed]
    public function rootSections()
    {
        return Section::whereNull('parent_id')->orderBy('sort_order')->get(['id', 'name']);
    }

    #[Computed]
    public function rentalItemTypes()
    {
        return RentalItemType::orderBy('name')->get(['id', 'name']);
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
            'product_id'          => '',
            'event_ids'           => [],
            'section_ids'         => [],
            'section_multi'       => false,
            'membership_type'     => '',
            'rental_item_type_id' => '',
            'options'             => [],
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
        $typeValues = implode(',', array_column(QuestionType::cases(), 'value'));

        $this->validate([
            'formTitle'           => 'required|string|max:255',
            'description'         => 'nullable|string|max:5000',
            'questions'           => 'array',
            'questions.*.label'   => 'required_unless:questions.*.type,section|nullable|string|max:500',
            'questions.*.type'    => 'required|string|in:' . $typeValues,
            'questions.*.number_min' => 'nullable|numeric',
            'questions.*.number_max' => 'nullable|numeric|gte:questions.*.number_min',
            'questions.*.product_id' => 'nullable|integer|exists:products,id',
        ]);

        $this->form->update([
            'title'       => $this->formTitle,
            'description' => $this->description ?: null,
        ]);

        $keptIds = [];

        foreach ($this->questions as $i => $q) {
            $type = QuestionType::from($q['type']);
            $data = [
                'type'             => $type,
                'label'            => $q['label'] ?: ($type->isSection() ? '' : $q['label']),
                'description'      => $q['description'] ?: null,
                'is_required'      => $type->isStructural() ? false : ($q['is_required'] ?? false),
                'sort_order'       => $i,
                'scale_min'        => $type->isScale() ? (int) $q['scale_min'] : null,
                'scale_max'        => $type->isScale() ? (int) $q['scale_max'] : null,
                'scale_icon'       => $type->isScale() ? $q['scale_icon'] : null,
                'datetime_variant' => $type->isDatetime() ? $q['datetime_variant'] : null,
                'number_min'       => $type === QuestionType::Number && $q['number_min'] !== '' ? (float) $q['number_min'] : null,
                'number_max'       => $type === QuestionType::Number && $q['number_max'] !== '' ? (float) $q['number_max'] : null,
                'product_id'          => $type->isProduct() && $q['product_id'] ? (int) $q['product_id'] : null,
                'event_ids'           => $type->isEventSelection() && ! empty($q['event_ids']) ? array_map('intval', $q['event_ids']) : null,
                'section_ids'         => $type->isSectionSelection() && ! empty($q['section_ids']) ? array_map('intval', $q['section_ids']) : null,
                'section_multi'       => $type->isSectionSelection() ? (bool) ($q['section_multi'] ?? false) : false,
                'membership_type'     => $type->isMembership() && $q['membership_type'] !== '' ? $q['membership_type'] : null,
                'rental_item_type_id' => $type->isRentalWaitlist() && $q['rental_item_type_id'] ? (int) $q['rental_item_type_id'] : null,
            ];

            if (! empty($q['_id'])) {
                $question = FormQuestion::find($q['_id']);
                $question?->update($data);
                $keptIds[] = $q['_id'];
            } else {
                $question  = $this->form->questions()->create($data);
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

        $this->form->questions()->whereNotIn('id', $keptIds)->each(function ($q) {
            $q->options()->delete();
            $q->delete();
        });

        AuditLog::record(
            action: 'form_updated',
            area: 'forms',
            subject: $this->form->title,
            auditable: $this->form,
        );

        $this->dispatch('notify', message: 'Formulär sparat');
    }
};

?>

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.forms.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Tillbaka</a>
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Redigera: {{ $form->title }}</h1>
        <div class="ml-auto flex gap-2">
            @if($form->submissions()->exists())
                <a href="{{ route('admin.forms.responses', $form) }}" wire:navigate class="btn btn-ghost btn-sm">
                    Svar ({{ $form->submissions()->count() }})
                </a>
            @endif
            <a href="{{ route('admin.forms.preview', $form) }}" wire:navigate class="btn btn-ghost btn-sm">Förhandsgranska</a>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">

        {{-- Formulärinformation --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Formulärinformation</span>
            </div>
            <div class="space-y-4 px-5 py-4">
                <div>
                    <label class="lbl">Titel *</label>
                    <input wire:model="formTitle" type="text" class="inp w-full" />
                    @error('formTitle') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Beskrivning</label>
                    <textarea wire:model="description" rows="3" class="inp w-full resize-none"></textarea>
                </div>
            </div>
        </div>

        {{-- Frågor --}}
        <div class="space-y-4">
            @foreach($questions as $i => $question)
                @php $type = App\Enums\QuestionType::from($question['type']); @endphp
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900
                    {{ $type->isSection() ? 'border-brand/40 bg-brand/5' : '' }}">
                    <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">
                            {{ $type->isSection() ? 'Avdelare' : 'Fråga ' . ($i + 1) }}
                        </span>
                        <div class="flex items-center gap-1">
                            <x-sort-buttons :id="$i" :index="$i" :count="count($questions)" />
                            <button type="button" wire:click="removeQuestion({{ $i }})"
                                    class="rounded px-2 py-0.5 text-red-400 hover:text-red-600">✕</button>
                        </div>
                    </div>
                    <div class="space-y-4 px-5 py-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">{{ $type->isSection() ? 'Rubrik' : 'Frågetext *' }}</label>
                                <input wire:model="questions.{{ $i }}.label" type="text" class="inp w-full" />
                                @error("questions.{$i}.label") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Typ</label>
                                <select wire:model.live="questions.{{ $i }}.type" class="inp w-full">
                                    @foreach(QuestionType::cases() as $qt)
                                        <option value="{{ $qt->value }}">{{ $qt->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if(! $type->isSection())
                            <div>
                                <label class="lbl">Hjälptext</label>
                                <input wire:model="questions.{{ $i }}.description" type="text" placeholder="Valfri förklaringstext" class="inp w-full" />
                            </div>
                            @if(! $type->isStructural())
                                <label class="flex items-center gap-2 text-sm">
                                    <input wire:model="questions.{{ $i }}.is_required" type="checkbox" class="rounded" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Obligatorisk</span>
                                </label>
                            @endif
                        @else
                            <div>
                                <label class="lbl">Brödtext</label>
                                <textarea wire:model="questions.{{ $i }}.description" rows="2" class="inp w-full resize-none" placeholder="Valfri text under rubriken"></textarea>
                            </div>
                        @endif

                        {{-- Scale config --}}
                        @if($type->isScale())
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

                        {{-- Datetime config --}}
                        @if($type->isDatetime())
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <label class="lbl">Variant</label>
                                <select wire:model="questions.{{ $i }}.datetime_variant" class="inp w-full">
                                    <option value="date">Datum</option>
                                    <option value="time">Tid</option>
                                    <option value="datetime">Datum & tid</option>
                                </select>
                            </div>
                        @endif

                        {{-- Number config --}}
                        @if($type === \App\Enums\QuestionType::Number)
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="lbl">Minsta värde <span class="font-normal text-zinc-400">(valfritt)</span></label>
                                        <input wire:model="questions.{{ $i }}.number_min" type="number" step="any" class="inp w-full" />
                                    </div>
                                    <div>
                                        <label class="lbl">Största värde <span class="font-normal text-zinc-400">(valfritt)</span></label>
                                        <input wire:model="questions.{{ $i }}.number_max" type="number" step="any" class="inp w-full" />
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Options for radio / checkbox / select --}}
                        @if($type->hasOptions())
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

                        {{-- Product config --}}
                        @if($type->isProduct())
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <label class="lbl">Produkt *</label>
                                <select wire:model="questions.{{ $i }}.product_id" class="inp w-full">
                                    <option value="">— Välj produkt —</option>
                                    @foreach($this->products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                                @error("questions.{$i}.product_id") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    Deltagaren ser produkten med sina varianter och kan tacka ja eller nej.
                                </p>
                            </div>
                        @endif

                        {{-- EventSelection config --}}
                        @if($type->isEventSelection())
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <label class="lbl">Tillgängliga event</label>
                                <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                                    Lämna tomt för att visa alla publicerade event. Välj specifika för att begränsa urvalet.
                                </p>
                                <div class="max-h-48 space-y-1.5 overflow-y-auto">
                                    @foreach($this->events as $event)
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="checkbox"
                                                   wire:model="questions.{{ $i }}.event_ids"
                                                   value="{{ $event->id }}"
                                                   class="rounded" />
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $event->title }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- SectionSelection config --}}
                        @if($type->isSectionSelection())
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800 space-y-4">
                                <div>
                                    <label class="lbl">Sektioner att erbjuda</label>
                                    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                                        Lämna tomt för att erbjuda alla rotsektioner. Välj specifika för att begränsa.
                                    </p>
                                    <div class="max-h-48 space-y-1.5 overflow-y-auto">
                                        @foreach($this->rootSections as $section)
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="checkbox"
                                                       wire:model="questions.{{ $i }}.section_ids"
                                                       value="{{ $section->id }}"
                                                       class="rounded" />
                                                <span class="text-zinc-700 dark:text-zinc-300">{{ $section->name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox"
                                           wire:model="questions.{{ $i }}.section_multi"
                                           class="rounded" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Tillåt val av flera sektioner</span>
                                </label>
                            </div>
                        @endif

                        {{-- Membership config --}}
                        @if($type->isMembership())
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <label class="lbl">Medlemskapstyp</label>
                                <input wire:model="questions.{{ $i }}.membership_type"
                                       type="text" placeholder="t.ex. standard" class="inp w-full" />
                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    Deltagaren kan tacka ja/nej. Väljer de ja läggs ett medlemskap i varukorgen.
                                </p>
                            </div>
                        @endif

                        {{-- RentalWaitlist config --}}
                        @if($type->isRentalWaitlist())
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                                <label class="lbl">Uthyrningsobjektstyp *</label>
                                <select wire:model="questions.{{ $i }}.rental_item_type_id" class="inp w-full">
                                    <option value="">— Välj —</option>
                                    @foreach($this->rentalItemTypes as $rit)
                                        <option value="{{ $rit->id }}">{{ $rit->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    Deltagaren kan anmäla sitt intresse för en specifik typ av hyrobjekt.
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            <button type="button" wire:click="addQuestion"
                    class="w-full rounded-xl border border-dashed border-zinc-300 py-3 text-sm text-zinc-500 hover:border-zinc-400 hover:text-zinc-700 dark:border-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-200">
                + Lägg till fält
            </button>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.forms.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
            <button type="submit" class="btn btn-primary">Spara ändringar</button>
        </div>
    </form>
</div>
