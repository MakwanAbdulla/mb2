@props([
    'survey',
    'heading',
    'description' => null,
    'detachAction',
])

@php
    use App\Enums\SurveyStatus;
    $count = $survey->questions()->count();
@endphp

<div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
    <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
        <div>
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $heading }}</span>
            @if($description)
                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $description }}</p>
            @endif
        </div>
        <span @class([
            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
            'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' => $survey->status === SurveyStatus::Active,
            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'        => $survey->status === SurveyStatus::Draft,
            'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'         => $survey->status === SurveyStatus::Closed,
        ])>
            {{ match($survey->status) {
                SurveyStatus::Draft  => 'Utkast',
                SurveyStatus::Active => 'Aktivt',
                SurveyStatus::Closed => 'Stängt',
            } }}
        </span>
    </div>
    <div class="px-5 py-4 space-y-3">
        <p class="font-medium text-zinc-900 dark:text-white">{{ $survey->title }}</p>
        @php $responseCount = $survey->submissions()->count(); @endphp
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ $count }} {{ $count === 1 ? 'fråga' : 'frågor' }}
            @if($responseCount > 0)
                · <span class="text-zinc-700 dark:text-zinc-300 font-medium">{{ $responseCount }} {{ $responseCount === 1 ? 'svar' : 'svar' }}</span>
            @endif
        </p>
        <div class="flex items-center gap-3 pt-1">
            <a href="{{ route('admin.surveys.edit', $survey) }}" wire:navigate class="btn btn-secondary btn-sm">
                <x-bootstrap-icon name="pencil" class="size-3.5" /> Redigera
            </a>
            @if($responseCount > 0)
                <a href="{{ route('admin.surveys.responses', $survey) }}" wire:navigate class="btn btn-ghost btn-sm">
                    <x-bootstrap-icon name="bar-chart" class="size-3.5" /> Visa svar
                </a>
            @endif
            <button wire:click="{{ $detachAction }}"
                    wire:confirm="Koppla bort formuläret från evenemanget? Formuläret tas inte bort."
                    class="btn btn-ghost btn-sm text-zinc-500">
                Koppla bort
            </button>
        </div>
    </div>
</div>
