<?php

use App\Enums\SurveyStatus;
use App\Models\AuditLog;
use App\Models\Survey\Survey;
use App\Notifications\SurveyOpenedNotification;
use App\Notifications\SurveyClosedNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Enkäter');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);
    }

    #[Computed]
    public function surveys()
    {
        return Survey::withCount(['submissions as responses_count', 'invitations'])
            ->with('creator')
            ->latest()
            ->get();
    }

    public function delete(int $id): void
    {
        // Livewire actions can be called without mount() — check again
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);

        $survey = Survey::findOrFail($id);
        $title  = $survey->title;
        $survey->delete();

        AuditLog::record(
            action: 'survey_deleted',
            area: 'surveys',
            subject: $title,
        );

        unset($this->surveys);
    }

    public function activate(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);

        $survey = Survey::findOrFail($id);
        $survey->update(['status' => SurveyStatus::Active]);

        AuditLog::record(
            action: 'survey_activated',
            area: 'surveys',
            subject: $survey->title,
            auditable: $survey,
        );

        $survey->creator?->notify(new SurveyOpenedNotification($survey));

        unset($this->surveys);
    }

    public function close(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('surveys.manage'), 403);

        $survey = Survey::findOrFail($id);
        $survey->update(['status' => SurveyStatus::Closed]);

        AuditLog::record(
            action: 'survey_closed',
            area: 'surveys',
            subject: $survey->title,
            auditable: $survey,
        );

        $survey->creator?->notify(new SurveyClosedNotification($survey));

        unset($this->surveys);
    }
}; ?>

<div>
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Enkäter</h1>
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Hantera enkäter och utvärderingar</p>
            </div>
            <a href="{{ route('admin.surveys.create') }}" wire:navigate class="btn btn-primary">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Ny enkät
            </a>
        </div>

        @if($this->surveys->isEmpty())
            <div class="rounded-xl border border-zinc-200 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <x-bootstrap-icon name="clipboard" class="mx-auto mb-4 size-12 text-zinc-400" />
                <p class="mb-1 font-medium text-zinc-700 dark:text-zinc-300">Inga enkäter än</p>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Skapa din första enkät för att börja samla in svar.</p>
                <a href="{{ route('admin.surveys.create') }}" wire:navigate class="btn btn-primary btn-sm">Skapa enkät</a>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                            <th class="px-5 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Titel</th>
                            <th class="px-5 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                            <th class="px-5 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Svar</th>
                            <th class="px-5 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Inbjudningar</th>
                            <th class="px-5 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Skapad av</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @foreach($this->surveys as $survey)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-5 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $survey->title }}</div>
                                    @if($survey->description)
                                        <div class="mt-0.5 max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $survey->description }}</div>
                                    @endif
                                    <div class="mt-1 flex gap-2 text-xs text-zinc-400">
                                        @if($survey->is_anonymous) <span>Anonym</span> @endif
                                        @if($survey->is_open) <span>Öppen</span> @endif
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    @php
                                        $statusColors = [
                                            'draft'  => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                                            'active' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                            'closed' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusColors[$survey->status->value] ?? '' }}">
                                        {{ $survey->status->label() }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">{{ $survey->responses_count }}</td>
                                <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">{{ $survey->invitations_count }}</td>
                                <td class="px-5 py-4 text-zinc-500 dark:text-zinc-400">{{ $survey->creator?->name ?? '—' }}</td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($survey->responses_count > 0)
                                            <a href="{{ route('admin.surveys.responses', $survey) }}" wire:navigate
                                               class="text-xs text-complement hover:underline dark:text-complement-light">Svar</a>
                                        @endif
                                        <a href="{{ route('admin.surveys.invitations', $survey) }}" wire:navigate
                                           class="text-xs text-complement hover:underline dark:text-complement-light">Inbjudningar</a>
                                        @if($survey->is_open)
                                            <button x-data
                                                    @click="navigator.clipboard.writeText('{{ route('surveys.respond', 'open-'.$survey->id) }}')"
                                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">
                                                Kopiera länk
                                            </button>
                                        @endif
                                        <a href="{{ route('admin.surveys.preview', $survey) }}" wire:navigate
                                           class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">Förhandsgranska</a>
                                        <a href="{{ route('admin.surveys.edit', $survey) }}" wire:navigate
                                           class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">Redigera</a>
                                        @if($survey->status === SurveyStatus::Draft)
                                            <button wire:click="activate({{ $survey->id }})"
                                                    wire:confirm="Aktivera enkäten '{{ $survey->title }}'? Den blir tillgänglig för svar."
                                                    class="text-xs text-green-600 hover:underline dark:text-green-400">Aktivera</button>
                                        @elseif($survey->status === SurveyStatus::Active)
                                            <button wire:click="close({{ $survey->id }})"
                                                    wire:confirm="Stäng enkäten '{{ $survey->title }}'? Inga fler svar kan lämnas."
                                                    class="text-xs text-amber-600 hover:underline dark:text-amber-400">Stäng</button>
                                        @endif
                                        <button wire:click="delete({{ $survey->id }})"
                                                wire:confirm="Radera enkäten '{{ $survey->title }}'? Alla frågor, inbjudningar och svar raderas permanent."
                                                class="text-xs text-red-600 hover:underline dark:text-red-400">Radera</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
