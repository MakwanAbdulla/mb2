@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.rentals.index')    => 'tilldelningar',
        $is('admin.rfid-tags.index')  => 'rfid',
        $is('admin.rentals.invoices') => 'fakturor',
        $is('admin.rentals.prices')   => 'nivaer',
        $is('admin.rfid-tags.prices') => 'rfid-avgifter',
        default                       => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
@endphp

<x-page-header title="Skåp/Taggar/Nycklar" permission="rentals.manage" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">

            @can('rentals.manage')
            <a href="{{ route('admin.rentals.index') }}" wire:navigate
               @class([$pill, $pa => $tab('tilldelningar'), $pi => !$tab('tilldelningar')])>
                Tilldelningar
            </a>
            @endcan

            @can('tags.manage')
            <a href="{{ route('admin.rfid-tags.index') }}" wire:navigate
               @class([$pill, $pa => $tab('rfid'), $pi => !$tab('rfid')])>
                RFID-taggar
            </a>
            @endcan

            @can('rentals.manage')
            <a href="{{ route('admin.rentals.invoices') }}" wire:navigate
               @class([$pill, $pa => $tab('fakturor'), $pi => !$tab('fakturor')])>
                Fakturor
            </a>

            <a href="{{ route('admin.rentals.prices') }}" wire:navigate
               @class([$pill, $pa => $tab('nivaer'), $pi => !$tab('nivaer')])>
                Hyresnivåer
            </a>
            @endcan

            @can('tags.manage')
            <a href="{{ route('admin.rfid-tags.prices') }}" wire:navigate
               @class([$pill, $pa => $tab('rfid-avgifter'), $pi => !$tab('rfid-avgifter')])>
                RFID-avgifter
            </a>
            @endcan

        </nav>
    </div>
</div>
