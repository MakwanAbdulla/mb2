@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('booking.admin.bookings.*')           => 'bokningar',
        $is('booking.admin.recurring-bookings.*') => 'aterkommande',
        $is('booking.admin.venue-applications.*') => 'lokalansokningar',
        $is('booking.admin.resources.*')          => 'rum',
        default                                   => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';
@endphp

<x-page-header title="Bokningar" permission="bookings.manage" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6">
        <nav class="flex flex-wrap gap-1.5">

            @can('bookings.manage')
            <a href="{{ route('booking.admin.bookings.index') }}" wire:navigate
               @class([$pill, $pa => $tab('bokningar'), $pi => !$tab('bokningar')])>
                Alla bokningar
            </a>

            <a href="{{ route('booking.admin.recurring-bookings.index') }}" wire:navigate
               @class([$pill, $pa => $tab('aterkommande'), $pi => !$tab('aterkommande')])>
                Återkommande
            </a>
            @endcan

            @can('bookings.applications')
            <a href="{{ route('booking.admin.venue-applications.index') }}" wire:navigate
               @class([$pill, $pa => $tab('lokalansokningar'), $pi => !$tab('lokalansokningar')])>
                Lokalansökningar
            </a>
            @endcan

            @can('bookings.categories')
            <a href="{{ route('booking.admin.resources.index') }}" wire:navigate
               @class([$pill, $pa => $tab('rum'), $pi => !$tab('rum')])>
                Rum
            </a>
            @endcan

        </nav>
    </div>
</div>
