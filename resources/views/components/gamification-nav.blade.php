@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.gamification.index')   => 'oversikt',
        $is('admin.gamification.tasks.*') => 'tasks',
        $is('admin.gamification.godkann') => 'godkann',
        default => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';

    $pending = app(\App\Services\GamificationService::class)->pendingCompletionsCount();
@endphp

<x-page-header title="Gamification" permission="gamification.manage" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">
            @can('gamification.manage')
            <a href="{{ route('admin.gamification.index') }}" wire:navigate
               @class([$pill, $pa => $tab('oversikt'), $pi => !$tab('oversikt')])>
                Översikt
            </a>
            <a href="{{ route('admin.gamification.tasks.index') }}" wire:navigate
               @class([$pill, $pa => $tab('tasks'), $pi => !$tab('tasks')])>
                Tasks
            </a>
            <a href="{{ route('admin.gamification.godkann') }}" wire:navigate
               @class([$pill, $pa => $tab('godkann'), $pi => !$tab('godkann')])>
                Godkännanden
                @if($pending > 0)
                    <span class="ml-0.5 inline-flex size-5 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">{{ $pending }}</span>
                @endif
            </a>
            @endcan
        </nav>
    </div>
</div>
