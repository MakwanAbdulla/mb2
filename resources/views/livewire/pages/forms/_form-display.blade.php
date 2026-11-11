{{--
    Shared partial: renders a form for display or preview.
    Variables:
      $form    — Form model with questions.options and questions.product.variants loaded
      $preview — bool, true = read-only preview mode
      $answers — array keyed by question id (used in fill.blade.php to bind wire:model)
--}}
@php $preview = $preview ?? false; @endphp

<div class="space-y-6">
    @if($form->description)
        <p class="text-zinc-600 dark:text-zinc-400">{{ $form->description }}</p>
    @endif

    @foreach($form->questions as $question)
        @php $type = $question->type; @endphp

        @if($type->isSection())
            {{-- Section divider --}}
            <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                @if($question->label)
                    <h2 class="text-base font-semibold text-zinc-800 dark:text-zinc-200">{{ $question->label }}</h2>
                @endif
                @if($question->description)
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $question->description }}</p>
                @endif
            </div>

        @elseif($type->isProduct())
            {{-- Product pre-order --}}
            @php $product = $question->product; @endphp
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-zinc-800 dark:text-zinc-200">
                            {{ $question->label ?: ($product?->name ?? 'Produkt') }}
                            @if($question->is_required) <span class="ml-1 text-red-500">*</span> @endif
                        </p>
                        @if($question->description)
                            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                        @endif
                    </div>
                    @if($product)
                        <p class="shrink-0 text-sm font-medium text-zinc-600 dark:text-zinc-300">
                            {{ number_format($product->priceFor(true) / 100, 2, ',', ' ') }} kr
                        </p>
                    @endif
                </div>

                @if(! $preview && $product)
                    <div class="mt-4 space-y-3">
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="q_{{ $question->id }}_opted"
                                       wire:model.live="answers.{{ $question->id }}.opted_in"
                                       value="1" class="accent-brand-dark" />
                                <span class="text-zinc-700 dark:text-zinc-300">Ja, jag vill ha en</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="q_{{ $question->id }}_opted"
                                       wire:model.live="answers.{{ $question->id }}.opted_in"
                                       value="0" class="accent-brand-dark" />
                                <span class="text-zinc-700 dark:text-zinc-300">Nej tack</span>
                            </label>
                        </div>

                        @if(($answers[$question->id]['opted_in'] ?? '0') === '1')
                            @php $variants = $product->variants->filter(fn ($v) => $v->option_value !== ''); @endphp
                            @if($variants->isNotEmpty())
                                <div>
                                    <label class="lbl">Storlek / variant</label>
                                    <select wire:model="answers.{{ $question->id }}.variant_id" class="inp w-full">
                                        <option value="">— Välj —</option>
                                        @foreach($variants as $variant)
                                            <option value="{{ $variant->id }}">{{ $variant->option_value }}
                                                @if(! $variant->isInStock()) (slut i lager) @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <input type="hidden" wire:model="answers.{{ $question->id }}.variant_id"
                                       value="{{ $product->defaultVariant()?->id }}" />
                            @endif
                        @endif
                    </div>
                @elseif($preview && $product)
                    <div class="mt-3 text-sm text-zinc-400 italic">Deltagaren väljer ja/nej + variant här</div>
                @endif
            </div>

        @elseif($type->isEventSelection())
            {{-- Event selection --}}
            <div class="space-y-1.5">
                <label class="lbl">
                    {{ $question->label }}
                    @if($question->is_required) <span class="ml-1 text-red-500">*</span> @endif
                </label>
                @if($question->description)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                @endif
                @if(! $preview)
                    @php
                        $availableEvents = $question->event_ids
                            ? \App\Models\Events\Event::whereIn('id', $question->event_ids)->orderBy('title')->get()
                            : \App\Models\Events\Event::where('status', 'published')->orderBy('title')->get();
                    @endphp
                    <select wire:model="answers.{{ $question->id }}" class="inp w-full">
                        <option value="">— Välj event —</option>
                        @foreach($availableEvents as $event)
                            <option value="{{ $event->id }}">{{ $event->title }}</option>
                        @endforeach
                    </select>
                @else
                    <div class="inp w-full text-zinc-400 dark:text-zinc-500 italic">Deltagaren väljer ett event här</div>
                @endif
            </div>

        @elseif($type->isSectionSelection())
            {{-- Section selection --}}
            <div class="space-y-1.5">
                <label class="lbl">
                    {{ $question->label }}
                    @if($question->is_required) <span class="ml-1 text-red-500">*</span> @endif
                </label>
                @if($question->description)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                @endif
                @if(! $preview)
                    @php
                        $availableSections = $question->section_ids
                            ? \App\Models\Organization\Section::whereIn('id', $question->section_ids)->orderBy('sort_order')->get()
                            : \App\Models\Organization\Section::whereNull('parent_id')->orderBy('sort_order')->get();
                    @endphp
                    <div class="space-y-1.5">
                        @foreach($availableSections as $section)
                            <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                @if($question->section_multi)
                                    <input type="checkbox"
                                           wire:model="answers.{{ $question->id }}"
                                           value="{{ $section->id }}"
                                           class="rounded accent-brand-dark" />
                                @else
                                    <input type="radio"
                                           name="q_{{ $question->id }}"
                                           wire:model="answers.{{ $question->id }}"
                                           value="{{ $section->id }}"
                                           class="accent-brand-dark" />
                                @endif
                                {{ $section->name }}
                            </label>
                        @endforeach
                    </div>
                @else
                    <div class="text-sm text-zinc-400 italic">
                        Deltagaren väljer {{ $question->section_multi ? 'en eller flera sektioner' : 'en sektion' }} här
                    </div>
                @endif
                @error("answers.{$question->id}") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @error("answers.{$question->id}.*") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

        @elseif($type->isMembership())
            {{-- Membership opt-in --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="font-semibold text-zinc-800 dark:text-zinc-200">
                    {{ $question->label ?: 'Bli medlem' }}
                    @if($question->is_required) <span class="ml-1 text-red-500">*</span> @endif
                </p>
                @if($question->description)
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                @endif
                @if(! $preview)
                    <div class="mt-4 flex gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="q_{{ $question->id }}_membership"
                                   wire:model="answers.{{ $question->id }}"
                                   value="1" class="accent-brand-dark" />
                            <span class="text-zinc-700 dark:text-zinc-300">Ja, jag vill bli medlem</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="q_{{ $question->id }}_membership"
                                   wire:model="answers.{{ $question->id }}"
                                   value="0" class="accent-brand-dark" />
                            <span class="text-zinc-700 dark:text-zinc-300">Nej tack</span>
                        </label>
                    </div>
                    @error("answers.{$question->id}") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @else
                    <div class="mt-3 text-sm text-zinc-400 italic">Deltagaren väljer ja/nej här</div>
                @endif
            </div>

        @elseif($type->isRentalWaitlist())
            {{-- Rental waitlist opt-in --}}
            @php $rit = $question->rentalItemType; @endphp
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="font-semibold text-zinc-800 dark:text-zinc-200">
                    {{ $question->label ?: ($rit?->name ?? 'Intresselista') }}
                    @if($question->is_required) <span class="ml-1 text-red-500">*</span> @endif
                </p>
                @if($question->description)
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                @endif
                @if(! $preview)
                    <div class="mt-4 flex gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="q_{{ $question->id }}_waitlist"
                                   wire:model="answers.{{ $question->id }}"
                                   value="1" class="accent-brand-dark" />
                            <span class="text-zinc-700 dark:text-zinc-300">Ja, anmäl mitt intresse</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="q_{{ $question->id }}_waitlist"
                                   wire:model="answers.{{ $question->id }}"
                                   value="0" class="accent-brand-dark" />
                            <span class="text-zinc-700 dark:text-zinc-300">Nej tack</span>
                        </label>
                    </div>
                    @error("answers.{$question->id}") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @else
                    <div class="mt-3 text-sm text-zinc-400 italic">Deltagaren anmäler intresse här</div>
                @endif
            </div>

        @else
            {{-- Standard question types --}}
            <div class="space-y-1.5">
                <label class="lbl" @if(! $preview) for="q_{{ $question->id }}" @endif>
                    {{ $question->label }}
                    @if($question->is_required) <span class="ml-1 text-red-500">*</span> @endif
                </label>
                @if($question->description)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                @endif

                @if($preview)
                    {{-- Preview rendering --}}
                    @if($type === \App\Enums\QuestionType::Text)
                        <input type="text" class="inp w-full" disabled placeholder="Kortsvarstext" />
                    @elseif($type === \App\Enums\QuestionType::Textarea)
                        <textarea class="inp w-full" rows="3" disabled placeholder="Fritextssvar"></textarea>
                    @elseif($type === \App\Enums\QuestionType::Email)
                        <input type="email" class="inp w-full" disabled placeholder="exempel@epost.se" />
                    @elseif($type === \App\Enums\QuestionType::Number)
                        <input type="number" class="inp w-full" disabled
                               min="{{ $question->number_min ?? '' }}" max="{{ $question->number_max ?? '' }}" />
                    @elseif(in_array($type, [\App\Enums\QuestionType::Radio, \App\Enums\QuestionType::Checkbox]))
                        <div class="space-y-1.5">
                            @foreach($question->options as $option)
                                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <input type="{{ $type === \App\Enums\QuestionType::Radio ? 'radio' : 'checkbox' }}" disabled class="rounded" />
                                    {{ $option->text }}
                                </label>
                            @endforeach
                        </div>
                    @elseif($type === \App\Enums\QuestionType::Select)
                        <select class="inp w-full" disabled>
                            <option>— Välj —</option>
                            @foreach($question->options as $option)
                                <option>{{ $option->text }}</option>
                            @endforeach
                        </select>
                    @elseif($type === \App\Enums\QuestionType::Scale)
                        <div class="flex gap-2">
                            @for($v = $question->scale_min; $v <= $question->scale_max; $v++)
                                <span class="rounded border border-zinc-200 px-2.5 py-1 text-sm text-zinc-500 dark:border-zinc-700">{{ $v }}</span>
                            @endfor
                        </div>
                    @elseif($type === \App\Enums\QuestionType::Datetime)
                        <input type="{{ $question->datetime_variant ?? 'date' }}" class="inp" disabled />
                    @endif

                @else
                    {{-- Live form inputs --}}
                    @if($type === \App\Enums\QuestionType::Text)
                        <input id="q_{{ $question->id }}" type="text" wire:model.blur="answers.{{ $question->id }}" class="inp w-full" />
                    @elseif($type === \App\Enums\QuestionType::Textarea)
                        <textarea id="q_{{ $question->id }}" wire:model.blur="answers.{{ $question->id }}" rows="4" class="inp w-full resize-none"></textarea>
                    @elseif($type === \App\Enums\QuestionType::Email)
                        <input id="q_{{ $question->id }}" type="email" wire:model.blur="answers.{{ $question->id }}" class="inp w-full" />
                    @elseif($type === \App\Enums\QuestionType::Number)
                        <input id="q_{{ $question->id }}" type="number" step="any"
                               min="{{ $question->number_min ?? '' }}" max="{{ $question->number_max ?? '' }}"
                               wire:model.blur="answers.{{ $question->id }}" class="inp w-full" />
                    @elseif($type === \App\Enums\QuestionType::Radio)
                        <div class="space-y-1.5">
                            @foreach($question->options as $option)
                                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <input type="radio" name="q_{{ $question->id }}"
                                           wire:model="answers.{{ $question->id }}"
                                           value="{{ $option->id }}" class="accent-brand-dark" />
                                    {{ $option->text }}
                                </label>
                            @endforeach
                        </div>
                    @elseif($type === \App\Enums\QuestionType::Checkbox)
                        <div class="space-y-1.5">
                            @foreach($question->options as $option)
                                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <input type="checkbox"
                                           wire:model="answers.{{ $question->id }}"
                                           value="{{ $option->id }}" class="rounded accent-brand-dark" />
                                    {{ $option->text }}
                                </label>
                            @endforeach
                        </div>
                    @elseif($type === \App\Enums\QuestionType::Select)
                        <select id="q_{{ $question->id }}" wire:model="answers.{{ $question->id }}" class="inp w-full">
                            <option value="">— Välj —</option>
                            @foreach($question->options as $option)
                                <option value="{{ $option->id }}">{{ $option->text }}</option>
                            @endforeach
                        </select>
                    @elseif($type === \App\Enums\QuestionType::Scale)
                        <div class="flex flex-wrap gap-2">
                            @for($v = $question->scale_min; $v <= $question->scale_max; $v++)
                                <label class="cursor-pointer">
                                    <input type="radio" name="q_{{ $question->id }}"
                                           wire:model="answers.{{ $question->id }}"
                                           value="{{ $v }}" class="sr-only" />
                                    <span class="flex size-10 items-center justify-center rounded-lg border-2 text-sm font-medium transition
                                                 {{ ($answers[$question->id] ?? '') == $v
                                                     ? 'border-brand bg-brand text-brand-dark'
                                                     : 'border-zinc-200 text-zinc-600 hover:border-brand dark:border-zinc-700 dark:text-zinc-400' }}">
                                        {{ $v }}
                                    </span>
                                </label>
                            @endfor
                        </div>
                    @elseif($type === \App\Enums\QuestionType::Datetime)
                        <input id="q_{{ $question->id }}"
                               type="{{ $question->datetime_variant === 'datetime' ? 'datetime-local' : ($question->datetime_variant ?? 'date') }}"
                               wire:model="answers.{{ $question->id }}" class="inp" />
                    @endif
                @endif

                @error("answers.{$question->id}") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif
    @endforeach
</div>
