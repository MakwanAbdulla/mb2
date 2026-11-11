<?php

use App\Enums\QuestionType;
use App\Models\Survey\Survey;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Svar');

new class extends Component {
    public Survey $survey;
    public bool   $showIndividual = false;

    public function mount(Survey $survey): void
    {
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);
        $this->survey = $survey;
    }

    #[Computed]
    public function totalResponses(): int
    {
        return $this->survey->submissions()->count();
    }

    #[Computed]
    public function individualResponses()
    {
        return $this->survey->submissions()
            ->with('answers.option', 'invitation', 'user')
            ->latest()
            ->get();
    }

    #[Computed]
    public function questionsWithData()
    {
        $submissionIds = $this->survey->submissions()->pluck('id');

        return $this->survey->questions->map(function ($question) use ($submissionIds) {
            $details = $question->answers()->whereIn('submission_id', $submissionIds)->with('option')->get();

            $data = match ($question->type) {
                QuestionType::Radio,
                QuestionType::Checkbox => $this->countOptions($question, $details),
                QuestionType::Scale    => $this->countScale($question, $details),
                default                => ['texts' => $details->pluck('answer_text')->filter()->values()],
            };

            return compact('question', 'details', 'data');
        });
    }

    private function countOptions($question, $details): array
    {
        $counts   = $details->groupBy('option_id')->map->count();
        $total    = $details->count();
        $options  = $question->options->map(function ($opt) use ($counts, $total) {
            $count = $counts[$opt->id] ?? 0;
            return [
                'text'    => $opt->text,
                'count'   => $count,
                'percent' => $total > 0 ? (int) round($count / $total * 100) : 0,
            ];
        });
        return compact('options', 'total');
    }

    private function countScale($question, $details): array
    {
        $values  = $details->pluck('answer_text')->filter()->map(fn($v) => (int) $v);
        $min     = $question->scale_min ?? 1;
        $max     = $question->scale_max ?? 5;
        $total   = $values->count();
        $average = $total > 0 ? round($values->avg(), 1) : null;

        $distribution = collect(range($min, $max))->map(function ($v) use ($values, $total) {
            $count = $values->filter(fn ($x) => $x === $v)->count();
            return [
                'value'   => $v,
                'count'   => $count,
                'percent' => $total > 0 ? (int) round($count / $total * 100) : 0,
            ];
        });

        return compact('distribution', 'average', 'total', 'min', 'max');
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 space-y-6">

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.surveys.edit', $survey) }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Redigera enkät</a>
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Svar: {{ $survey->title }}</h1>
        <div class="ml-auto flex gap-2">
            <a href="{{ route('admin.surveys.invitations', $survey) }}" wire:navigate class="btn btn-ghost btn-sm">Inbjudningar</a>
            @if($this->totalResponses > 0)
                <a href="{{ route('admin.surveys.responses.export', $survey) }}" class="btn btn-ghost btn-sm">
                    <x-bootstrap-icon name="download" class="size-4" />
                    Exportera CSV
                </a>
            @endif
        </div>
    </div>

    {{-- Sammanfattning --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between px-5 py-4">
            <div>
                <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Totalt antal svar</p>
                <p class="mt-0.5 text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->totalResponses }}</p>
            </div>
            @php
                $invitationCount = $survey->invitations()->count();
                $respondedCount  = $survey->invitations()->whereNotNull('responded_at')->count();
            @endphp
            @if($invitationCount > 0)
                <div class="text-right text-sm text-zinc-500 dark:text-zinc-400">
                    <p>{{ $invitationCount }} inbjudna</p>
                    <p>{{ $respondedCount }} svarat</p>
                    <p class="font-semibold">{{ round($respondedCount / $invitationCount * 100) }}% svarsfrekvens</p>
                </div>
            @endif
        </div>
    </div>

    @if($this->totalResponses === 0)
        <div class="rounded-xl border border-zinc-200 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga svar har inkommit ännu.</p>
        </div>
    @else
        {{-- En sektion per fråga --}}
        @foreach($this->questionsWithData as $index => $item)
            @php $question = $item['question']; $data = $item['data']; @endphp
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1 flex items-start justify-between gap-4">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $index + 1 }}. {{ $question->label }}</h3>
                    <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ $question->type->label() }}
                    </span>
                </div>
                @if($question->description)
                    <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                @endif

                {{-- Radio / Checkbox --}}
                @if(in_array($question->type, [QuestionType::Radio, QuestionType::Checkbox]))
                    <div class="mt-4 space-y-2">
                        @foreach($data['options'] as $opt)
                            <div>
                                <div class="mb-0.5 flex justify-between text-sm">
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $opt['text'] }}</span>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $opt['count'] }} ({{ $opt['percent'] }}%)</span>
                                </div>
                                <div class="h-5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                    <div class="h-full rounded-full bg-complement transition-all duration-500" style="width: {{ $opt['percent'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                        <p class="mt-2 text-xs text-zinc-400">{{ $data['total'] }} svar totalt</p>
                    </div>
                @endif

                {{-- Skala --}}
                @if($question->type === QuestionType::Scale)
                    <div class="mt-4">
                        @if($data['average'] !== null)
                            <div class="mb-4 flex items-baseline gap-2">
                                <span class="text-3xl font-bold text-zinc-900 dark:text-white">
                                    @if($question->scale_icon === 'star') ⭐
                                    @elseif($question->scale_icon === 'heart') ❤️
                                    @elseif($question->scale_icon === 'thumb') 👍
                                    @endif
                                    {{ $data['average'] }}
                                </span>
                                <span class="text-sm text-zinc-500">/ {{ $data['max'] }} snitt ({{ $data['total'] }} svar)</span>
                            </div>
                        @endif
                        <div class="space-y-2">
                            @foreach($data['distribution'] as $row)
                                <div class="flex items-center gap-3">
                                    <span class="w-6 text-right text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ $row['value'] }}</span>
                                    <div class="flex-1 h-4 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                        <div class="h-full rounded-full bg-brand-orange transition-all duration-500" style="width: {{ $row['percent'] }}%"></div>
                                    </div>
                                    <span class="w-16 text-xs text-zinc-500">{{ $row['count'] }} ({{ $row['percent'] }}%)</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Text / Textarea / Datetime --}}
                @if(in_array($question->type, [QuestionType::Text, QuestionType::Textarea, QuestionType::Datetime]))
                    @if($data['texts']->isEmpty())
                        <p class="mt-4 text-sm text-zinc-400">Inga svar.</p>
                    @else
                        <ul class="mt-4 space-y-2">
                            @foreach($data['texts'] as $text)
                                <li class="rounded-lg bg-zinc-50 px-4 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ $text }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        @endforeach

        {{-- Individuella svar --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Individuella svar</span>
                <button wire:click="$toggle('showIndividual')"
                        class="text-xs text-complement hover:underline dark:text-complement-light">
                    {{ $showIndividual ? 'Dölj' : 'Visa' }}
                </button>
            </div>

            @if($showIndividual)
                <div class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach($this->individualResponses as $nr => $response)
                        <div class="px-5 py-4">
                            <div class="mb-3 flex flex-wrap items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                                <span class="font-semibold text-zinc-700 dark:text-zinc-300">Svar #{{ $nr + 1 }}</span>
                                <span>{{ $response->created_at->format('Y-m-d H:i') }}</span>
                                @if($response->invitation)
                                    <span>{{ $response->invitation->email }}</span>
                                @elseif($response->user)
                                    <span>{{ $response->user->name }}</span>
                                @elseif(! $survey->is_anonymous)
                                    <span>Okänd</span>
                                @else
                                    <span class="italic">Anonym</span>
                                @endif
                            </div>
                            <div class="space-y-2">
                                @foreach($survey->questions as $question)
                                    @php
                                        $answers    = $response->answers->where('question_id', $question->id);
                                        $answerText = match ($question->type) {
                                            QuestionType::Radio,
                                            QuestionType::Checkbox =>
                                                $answers->map(fn ($d) => $d->option?->text)->filter()->implode(', ') ?: '—',
                                            default => $answers->first()?->answer_text ?: '—',
                                        };
                                    @endphp
                                    <div class="grid grid-cols-3 gap-2 text-sm">
                                        <span class="truncate text-zinc-500 dark:text-zinc-400" title="{{ $question->label }}">{{ $question->label }}</span>
                                        <span class="col-span-2 text-zinc-800 dark:text-zinc-200">{{ $answerText }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
