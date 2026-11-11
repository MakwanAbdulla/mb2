@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.workspace.index')     => 'konton',
        $is('admin.workspace.drive')     => 'drive',
        $is('admin.workspace.mailboxes') => 'inkorgar',
        $is('admin.workspace.reports')   => 'rapporter',
        default                          => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
@endphp

<x-page-header title="Google Workspace" permission="workspace.manage" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">
            <a href="{{ route('admin.workspace.index') }}" wire:navigate
               @class([$pill, $pa => $tab('konton'), $pi => !$tab('konton')])>
                <x-bootstrap-icon name="people" class="size-4 shrink-0" />
                Konton
            </a>
            <a href="{{ route('admin.workspace.drive') }}" wire:navigate
               @class([$pill, $pa => $tab('drive'), $pi => !$tab('drive')])>
                <x-bootstrap-icon name="hdd" class="size-4 shrink-0" />
                Shared Drives
            </a>
            <a href="{{ route('admin.workspace.mailboxes') }}" wire:navigate
               @class([$pill, $pa => $tab('inkorgar'), $pi => !$tab('inkorgar')])>
                <x-bootstrap-icon name="inbox" class="size-4 shrink-0" />
                Inkorgar
            </a>
            <a href="{{ route('admin.workspace.reports') }}" wire:navigate
               @class([$pill, $pa => $tab('rapporter'), $pi => !$tab('rapporter')])>
                <x-bootstrap-icon name="bar-chart" class="size-4 shrink-0" />
                Rapporter
            </a>
        </nav>
    </div>
</div>
