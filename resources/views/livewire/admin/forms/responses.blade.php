<?php

use App\Enums\QuestionType;
use App\Models\Form\Form;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Formulärsvar');

new class extends Component {
    public Form $form;
    public bool $showIndividual = false;

    public function mount(Form $form): void
    {
        abort_unless(Auth::user()->hasPermissionTo('forms.manage'), 403);
        $this->form = $form;
    }

    #[Computed]
    public function totalResponses(): int
    {
        return $this->form->submissions()->count();
    }

    #[Computed]
    public function individualResponses()
    {
        return $this->form->submissions()
            ->with('answers.option', 'user')
            ->latest()
            ->get();
    }

    #[Computed]
    public function questionsWithData()
    {
        $submissionIds = $this->form->submissions()->pluck('id');

        return $this->form->questions->map(function ($question) use ($submissionIds) {
            $answers = $question->answers()->whereIn('submission_id', $submissionIds)->with('option')->get();

            $data = match ($question->type) {
                QuestionType::Radio,
                QuestionType::Checkbox,
                QuestionType::Select  => $this->countOptions($question, $answers),
                QuestionType::Scale   => $this->countScale($question, $answers),
                QuestionType::Product => $this->countProduct($question, $answers),
                default               => ['texts' => $answers->pluck('answer_text')->filter()->values()],
            };

            return compact('question', 'answers', 'data');
        });
    }

    private function countOptions($question, $answers): array
    {
        $counts = $answers->groupBy('option_id')->map->count();
        $total  = $answers->count();

        return [
            'options' => $question->options->map(function ($opt) use ($counts, $total) {
                $count = $counts[$opt->id] ?? 0;
                return [
                    'label'   => $opt->text,
                    'count'   => $count,
                    'percent' => $total > 0 ? round($count / $total * 100) : 0,
                ];
            }),
        ];
    }

    private function countScale($question, $answers): array
    {
        $values = $answers->pluck('answer_text')->filter()->map(fn ($v) => (int) $v);
        return [
            'avg'    => $values->isNotEmpty() ? round($values->average(), 1) : null,
            'min'    => $values->min(),
            'max'    => $values->max(),
            'counts' => $values->countBy()->sortKeys(),
        ];
    }

    private function countProduct($question, $answers): array
    {
        $decoded = $answers->pluck('answer_text')->filter()->map(fn ($v) => json_decode($v, true));
        $optedIn = $decoded->filter(fn ($d) => ($d['opted_in'] ?? false));

        return [
            'opted_in'  => $optedIn->count(),
            'opted_out' => $answers->count() - $optedIn->count(),
            'variants'  => $optedIn->groupBy('variant_label')->map->count(),
        ];
    }
};

?>

<div>
    <x-page-header title="Svar: {{ $form->title }}">
        <x-slot name="back">
            <a href="{{ route('admin.forms.edit', $form) }}" wire:navigate class="btn btn-ghost btn-sm">
                <x-bootstrap-icon name="arrow-left" class="size-4" />
                Tillbaka
            </a>
        </x-slot>
    </x-page-header>

    <div class="mb-6 flex items-center gap-4">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Totalt <strong>{{ $this->totalResponses }}</strong> svar</p>
        @if($this->totalResponses > 0)
            <button wire:click="$toggle('showIndividual')" class="btn btn-ghost btn-sm">
                {{ $showIndividual ? 'Dölj individsvar' : 'Visa individsvar' }}
            </button>
        @endif
    </div>

    @if($this->totalResponses === 0)
        <div class="rounded-xl border border-zinc-200 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="inbox" class="mx-auto mb-4 size-12 text-zinc-400" />
            <p class="font-medium text-zinc-700 dark:text-zinc-300">Inga svar har lämnats ännu.</p>
        </div>
    @else
        <div class="space-y-6">
            @foreach($this->questionsWithData as $row)
                @php $q = $row['question']; $data = $row['data']; @endphp
                @if($q->type->isSection() || $q->type->isStructural())
                    @continue
                @endif
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $q->label }}</p>
                        <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $q->type->label() }}</p>
                    </div>
                    <div class="px-5 py-4">
                        @if(isset($data['options']))
                            <div class="space-y-2">
                                @foreach($data['options'] as $opt)
                                    <div class="flex items-center gap-3">
                                        <div class="w-32 shrink-0 truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $opt['label'] }}</div>
                                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                            <div class="h-2 rounded-full bg-complement dark:bg-complement-light" style="width: {{ $opt['percent'] }}%"></div>
                                        </div>
                                        <div class="w-16 text-right text-sm text-zinc-500">{{ $opt['count'] }} ({{ $opt['percent'] }}%)</div>
                                    </div>
                                @endforeach
                            </div>
                        @elseif(isset($data['avg']))
                            <div class="flex items-center gap-6">
                                <div class="text-3xl font-bold text-brand-dark dark:text-brand">{{ $data['avg'] }}</div>
                                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                    Min: {{ $data['min'] }} / Max: {{ $data['max'] }}
                                </div>
                            </div>
                        @elseif(isset($data['opted_in']))
                            <div class="flex items-center gap-6">
                                <div>
                                    <span class="text-lg font-bold text-green-600 dark:text-green-400">{{ $data['opted_in'] }}</span>
                                    <span class="ml-1 text-sm text-zinc-500">tackade ja</span>
                                </div>
                                <div>
                                    <span class="text-lg font-bold text-zinc-500">{{ $data['opted_out'] }}</span>
                                    <span class="ml-1 text-sm text-zinc-500">tackade nej</span>
                                </div>
                            </div>
                            @if($data['variants']->isNotEmpty())
                                <div class="mt-3 space-y-1">
                                    @foreach($data['variants'] as $variant => $count)
                                        <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $variant }}: <strong>{{ $count }}</strong></div>
                                    @endforeach
                                </div>
                            @endif
                        @elseif(isset($data['texts']))
                            <div class="max-h-48 space-y-1 overflow-y-auto">
                                @foreach($data['texts'] as $text)
                                    <p class="rounded bg-zinc-50 px-3 py-1.5 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $text }}</p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if($showIndividual)
            <div class="mt-8">
                <h2 class="mb-4 text-base font-semibold text-zinc-700 dark:text-zinc-300">Individsvar</h2>
                <div class="space-y-4">
                    @foreach($this->individualResponses as $submission)
                        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $submission->user?->name ?? 'Anonym' }}
                                    · {{ $submission->created_at->format('d/m Y H:i') }}
                                </p>
                            </div>
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($submission->answers as $answer)
                                    <div class="px-5 py-3">
                                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                            {{ $answer->question?->label }}
                                        </p>
                                        <p class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-300">
                                            {{ $answer->option?->text ?? $answer->answer_text ?? '—' }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
