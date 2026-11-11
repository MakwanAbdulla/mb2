@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.meetings.index', 'admin.meetings.show', 'admin.meetings.create', 'admin.meetings.edit') => 'moten',
        $is('admin.tasks.*')              => 'uppgifter',
        $is('admin.submissions.*')        => 'arenden',
        $is('admin.meetings.templates.*') => 'mallar',
        default                           => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
@endphp

<x-page-header title="Möten" permission="meetings.view" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">

            @can('meetings.view')
            <a href="{{ route('admin.meetings.index') }}" wire:navigate
               @class([$pill, $pa => $tab('moten'), $pi => !$tab('moten')])>
                Möten
            </a>
            @endcan

            @can('tasks.view')
            <a href="{{ route('admin.tasks.index') }}" wire:navigate
               @class([$pill, $pa => $tab('uppgifter'), $pi => !$tab('uppgifter')])>
                Uppgifter
            </a>
            @endcan

            @can('submissions.view')
            <a href="{{ route('admin.submissions.index') }}" wire:navigate
               @class([$pill, $pa => $tab('arenden'), $pi => !$tab('arenden')])>
                Ärenden
            </a>
            @endcan

            @can('meetings.manage')
            <a href="{{ route('admin.meetings.templates.index') }}" wire:navigate
               @class([$pill, $pa => $tab('mallar'), $pi => !$tab('mallar')])>
                Mallar
            </a>
            @endcan

        </nav>
    </div>
</div>
