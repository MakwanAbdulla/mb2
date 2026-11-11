@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.pages.*')        => 'sidor',
        $is('admin.navigation.*')   => 'navigation',
        $is('admin.help-texts.*')   => 'sidtexter',
        $is('admin.help-sections.*')=> 'hjalpsektioner',
        $is('admin.contact.*')      => 'kontakt',
        $is('admin.notices.*')      => 'notiser',
        default                     => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
@endphp

<x-page-header title="Webb" permission="cms.edit" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">

            <a href="{{ route('admin.pages.index') }}" wire:navigate
               @class([$pill, $pa => $tab('sidor'), $pi => !$tab('sidor')])>
                Sidor
            </a>

            <a href="{{ route('admin.navigation.index') }}" wire:navigate
               @class([$pill, $pa => $tab('navigation'), $pi => !$tab('navigation')])>
                Navigation
            </a>

            <a href="{{ route('admin.help-texts.index') }}" wire:navigate
               @class([$pill, $pa => $tab('sidtexter'), $pi => !$tab('sidtexter')])>
                Sidtexter
            </a>

            @can('help.manage')
            <a href="{{ route('admin.help-sections.index') }}" wire:navigate
               @class([$pill, $pa => $tab('hjalpsektioner'), $pi => !$tab('hjalpsektioner')])>
                Hjälpsektioner
            </a>
            @endcan

            <a href="{{ route('admin.contact.index') }}" wire:navigate
               @class([$pill, $pa => $tab('kontakt'), $pi => !$tab('kontakt')])>
                Kontaktmeddelanden
            </a>

            @can('notices.manage')
            <a href="{{ route('admin.notices.index') }}" wire:navigate
               @class([$pill, $pa => $tab('notiser'), $pi => !$tab('notiser')])>
                Notiser
            </a>
            @endcan

        </nav>
    </div>
</div>
