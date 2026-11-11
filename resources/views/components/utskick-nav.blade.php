@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.mailings.*')          => 'utskick',
        $is('admin.mailing-templates.*') => 'mallar',
        $is('admin.surveys.*')           => 'enkater',
        default                         => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
@endphp

<x-page-header title="Utskick" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">

            @can('mailings.manage')
            <a href="{{ route('admin.mailings.index') }}" wire:navigate
               @class([$pill, $pa => $tab('utskick'), $pi => !$tab('utskick')])>
                Utskick
            </a>
            <a href="{{ route('admin.mailing-templates.index') }}" wire:navigate
               @class([$pill, $pa => $tab('mallar'), $pi => !$tab('mallar')])>
                Mallar
            </a>
            @endcan

            @can('surveys.manage')
            <a href="{{ route('admin.surveys.index') }}" wire:navigate
               @class([$pill, $pa => $tab('enkater'), $pi => !$tab('enkater')])>
                Enkäter
            </a>
            @endcan

        </nav>
    </div>
</div>
