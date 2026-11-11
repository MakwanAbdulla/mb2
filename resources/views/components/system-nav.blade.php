@php
    $is  = fn(string ...$patterns) => request()->routeIs(...$patterns);
    $qtab = request()->query('tab', 'bokningar');

    $activeTab = match(true) {
        $is('admin.settings') && $qtab === 'allmant'    => 'settings-allmant',
        $is('admin.settings') && $qtab === 'moduler'    => 'settings-moduler',
        $is('admin.settings') && $qtab === 'bokningar'  => 'settings-bokningar',
        $is('admin.settings') && $qtab === 'medlemskap' => 'settings-medlemskap',
        $is('admin.settings') && $qtab === 'betalning'  => 'settings-betalning',
        $is('admin.settings') && $qtab === 'discord'    => 'settings-discord',
        $is('admin.settings') && $qtab === 'serverinfo' => 'settings-serverinfo',
        $is('admin.settings')     => 'settings-allmant',
        $is('admin.tags.*')       => 'taggar',
        $is('admin.activities.*') => 'aktiviteter',
        $is('admin.roles.*')      => 'roller',
        $is('admin.users.*')      => 'anvandare',
        $is('admin.audit-log')    => 'handelselogg',
        default                   => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
@endphp

<x-page-header title="System" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">

            @can('settings.manage')
            <a href="{{ route('admin.settings') }}?tab=allmant" wire:navigate
               @class([$pill, $pa => $tab('settings-allmant'), $pi => !$tab('settings-allmant')])>
                Allmänt
            </a>
            <a href="{{ route('admin.settings') }}?tab=moduler" wire:navigate
               @class([$pill, $pa => $tab('settings-moduler'), $pi => !$tab('settings-moduler')])>
                Moduler
            </a>
            <a href="{{ route('admin.settings') }}?tab=bokningar" wire:navigate
               @class([$pill, $pa => $tab('settings-bokningar'), $pi => !$tab('settings-bokningar')])>
                Bokningar
            </a>
            <a href="{{ route('admin.settings') }}?tab=medlemskap" wire:navigate
               @class([$pill, $pa => $tab('settings-medlemskap'), $pi => !$tab('settings-medlemskap')])>
                Medlemskap
            </a>
            <a href="{{ route('admin.settings') }}?tab=betalning" wire:navigate
               @class([$pill, $pa => $tab('settings-betalning'), $pi => !$tab('settings-betalning')])>
                Betalning
            </a>
            <a href="{{ route('admin.settings') }}?tab=discord" wire:navigate
               @class([$pill, $pa => $tab('settings-discord'), $pi => !$tab('settings-discord')])>
                Discord
            </a>
            <a href="{{ route('admin.settings') }}?tab=grupper" wire:navigate
               @class([$pill, $pa => $tab('settings-grupper'), $pi => !$tab('settings-grupper')])>
                Grupper
            </a>
            <a href="{{ route('admin.settings') }}?tab=serverinfo" wire:navigate
               @class([$pill, $pa => $tab('settings-serverinfo'), $pi => !$tab('settings-serverinfo')])>
                Serverinfo
            </a>
            @endcan

            @if(auth()->user()?->isStaff())
            <a href="{{ route('admin.tags.index') }}" wire:navigate
               @class([$pill, $pa => $tab('taggar'), $pi => !$tab('taggar')])>
                Taggar
            </a>

            <a href="{{ route('admin.activities.index') }}" wire:navigate
               @class([$pill, $pa => $tab('aktiviteter'), $pi => !$tab('aktiviteter')])>
                Aktiviteter
            </a>
            @endif

            @can('users.manage-roles')
            <a href="{{ route('admin.roles.index') }}" wire:navigate
               @class([$pill, $pa => $tab('roller'), $pi => !$tab('roller')])>
                Roller
            </a>

            <a href="{{ route('admin.users.index') }}" wire:navigate
               @class([$pill, $pa => $tab('anvandare'), $pi => !$tab('anvandare')])>
                Användare utan medlemskap
            </a>
            @endcan

            @can('audit.view')
            <a href="{{ route('admin.audit-log') }}" wire:navigate
               @class([$pill, $pa => $tab('handelselogg'), $pi => !$tab('handelselogg')])>
                Händelselogg
            </a>
            @endcan

        </nav>
    </div>
</div>
