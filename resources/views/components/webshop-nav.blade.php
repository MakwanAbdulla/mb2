@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.shop.products.*')  => 'produkter',
        $is('admin.shop.orders.*')    => 'ordrar',
        $is('admin.shop.customers.*') => 'kunder',
        $is('admin.shop.invoices.*')  => 'kundfakturor',
        $is('admin.shop.discounts.*') => 'rabatter',
        default                       => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';

@endphp

<x-page-header title="Webshop" permission="shop.manage" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6 space-y-2">

        {{-- Row 1: group-level pills --}}
        <nav class="flex flex-wrap gap-1.5">

            <a href="{{ route('admin.shop.products.index') }}" wire:navigate
               @class([$pill, $pa => $tab('produkter'), $pi => !$tab('produkter')])>
                Produkter
            </a>

            <a href="{{ route('admin.shop.orders.index') }}" wire:navigate
               @class([$pill, $pa => $tab('ordrar'), $pi => !$tab('ordrar')])>
                Ordrar
            </a>

            <a href="{{ route('admin.shop.customers.index') }}" wire:navigate
               @class([$pill, $pa => $tab('kunder'), $pi => !$tab('kunder')])>
                Kunder
            </a>

            <a href="{{ route('admin.shop.invoices.index') }}" wire:navigate
               @class([$pill, $pa => $tab('kundfakturor'), $pi => !$tab('kundfakturor')])>
                Kundfakturor
            </a>

            <a href="{{ route('admin.shop.discounts.index') }}" wire:navigate
               @class([$pill, $pa => $tab('rabatter'), $pi => !$tab('rabatter')])>
                Rabatter
            </a>

        </nav>

    </div>
</div>
