<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="{{ auth()->check() && auth()->user()->ui_theme === 'dark' ? 'dark' : '' }}"
>
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-warm-dark">
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto flex h-14 max-w-7xl items-center gap-4 px-4 sm:px-6 lg:px-8">
                <a href="{{ route('dashboard') }}" class="flex items-center" wire:navigate>
                    <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-8 dark:hidden">
                    <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="{{ config('app.name') }}" class="h-8 hidden dark:block">
                </a>

                <div class="flex-1"></div>

                @auth
                    {{-- Booking navigation --}}
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button @click="open = !open"
                                class="flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
                            Bokning
                            <x-bootstrap-icon name="chevron-down" class="h-4 w-4 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                        </button>
                        <div x-show="open" x-cloak
                             class="absolute right-0 top-full z-50 mt-1 min-w-[200px] overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            <a href="{{ route('booking.calendar') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Kalender
                            </a>
                            @if(auth()->user()->canBook())
                            <a href="{{ route('booking.create') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Boka rum
                            </a>
                            <a href="{{ route('booking.my') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Mina bokningar
                            </a>
                            @endif
                            <a href="{{ route('booking.my-applications') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Mina ansökningar
                            </a>
                            <a href="{{ route('booking.resources.index') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Rum &amp; lokaler
                            </a>
                            @if(auth()->user()->canBook())
                            <a href="{{ route('booking.venue-application.create') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Ansök om lokal
                            </a>
                            @endif
                        </div>
                    </div>

                    {{-- Admin navigation for website editors --}}
                    @if(auth()->user()->isStaff())
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                            <button @click="open = !open"
                                    class="flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
                                Admin
                                <x-bootstrap-icon name="chevron-down" class="h-4 w-4 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                            </button>
                            <div x-show="open" x-cloak
                                 class="absolute right-0 top-full z-50 mt-1 min-w-[220px] overflow-y-auto rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
                                 style="max-height: calc(100vh - 4rem)"
                                {{-- Website --}}
                                @canany(['cms.edit', 'cms.publish', 'notices.manage'])
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Webbplats</p>
                                @canany(['cms.edit', 'cms.publish'])
                                <a href="{{ route('admin.pages.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Sidor
                                </a>
                                <a href="{{ route('admin.navigation.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Navigation
                                </a>
                                <a href="{{ route('admin.help-texts.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Sidtexter
                                </a>
                                @endcanany
                                @can('notices.manage')
                                <a href="{{ route('admin.notices.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Notiser
                                </a>
                                @endcan
                                <a href="{{ route('admin.contact.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Kontaktmeddelanden
                                </a>
                                @endcanany
                                {{-- Members --}}
                                @canany(['members.view', 'members.edit', 'members.manage', 'membership-periods.manage', 'payments.view', 'payments.confirm', 'payments.import'])
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Medlemmar</p>
                                @canany(['members.view', 'members.edit', 'members.manage'])
                                <a href="{{ route('admin.members.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Medlemmar
                                </a>
                                @endcanany
                                @can('sections.manage')
                                <a href="{{ route('admin.sections.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Grupper
                                </a>
                                @endcan
                                @can('members.manage')
                                <a href="{{ route('admin.membership-fees.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Avgifter
                                </a>
                                <a href="{{ route('admin.discounts.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Rabatter
                                </a>
                                <a href="{{ route('admin.discounts.scan') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Skanningsstation
                                </a>
                                @endcan
                                @canany(['payments.view', 'payments.confirm', 'payments.import'])
                                <a href="{{ route('admin.payments.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Betalningar
                                </a>
                                @endcanany
                                @canany('rentals.manage', 'tags.manage')
                                <a href="{{ route('admin.rentals.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Skåp/Taggar/Nycklar
                                </a>
                                @endcanany
                                @can('surveys.manage')
                                <a href="{{ route('admin.surveys.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Enkäter
                                </a>
                                @endcan
                                @endcanany
                                {{-- Möten --}}
                                @canany(['meetings.view', 'meetings.manage'])
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Möten</p>
                                <a href="{{ route('admin.meetings.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Alla möten
                                </a>
                                @can('meetings.manage')
                                <a href="{{ route('admin.meetings.templates.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Mötesmallar
                                </a>
                                @endcan
                                @endcanany
                                {{-- Booking --}}
                                @canany(['bookings.manage', 'bookings.categories', 'bookings.applications', 'bookings.statistics', 'bookings.super'])
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Bokningssystem</p>
                                @canany(['bookings.manage', 'bookings.super'])
                                <a href="{{ route('booking.admin.bookings.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Bokningar
                                </a>
                                <a href="{{ route('booking.admin.recurring-bookings.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Återkommande
                                </a>
                                @endcanany
                                @can('bookings.applications')
                                <a href="{{ route('booking.admin.venue-applications.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Lokalansökningar
                                </a>
                                @endcan
                                @can('bookings.categories')
                                <a href="{{ route('booking.admin.resources.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Rum
                                </a>
                                <a href="{{ route('booking.admin.categories.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Kategorier
                                </a>
                                @endcan
                                @can('bookings.statistics')
                                <a href="{{ route('booking.admin.statistics') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Statistik
                                </a>
                                @endcan
                                @can('settings.manage')
                                <a href="{{ route('admin.settings') }}#bokningar" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Inställningar
                                </a>
                                @endcan
                                @can('settings.manage')
                                <a href="{{ route('admin.mail-templates.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Mailmallar
                                </a>
                                @endcan
                                @endcanany
                                {{-- Events --}}
                                @canany(['events.view', 'events.create', 'events.manage-own', 'events.manage-all', 'events.check-in'])
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Evenemang</p>
                                <a href="{{ route('admin.events.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Evenemang
                                </a>
                                @endcanany
                                {{-- Statistik --}}
                                @can('statistics.view')
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Statistik</p>
                                <a href="{{ route('admin.statistics.members') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Medlemmar
                                </a>
                                <a href="{{ route('admin.statistics.events') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Evenemang
                                </a>
                                <a href="{{ route('admin.statistics.revenue') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Intäkter
                                </a>
                                <a href="{{ route('admin.statistics.municipality-report') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Kommunrapport
                                </a>
                                @endcan
                                {{-- Butik --}}
                                @can('shop.manage')
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Butik</p>
                                <a href="{{ route('admin.shop.products.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Produkter
                                </a>
                                <a href="{{ route('admin.shop.orders.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Ordrar
                                </a>
                                @endcan
                                {{-- Bokföring --}}
                                @canany(['accounting.view', 'accounting.manage'])
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Bokföring</p>
                                <a href="{{ route('admin.accounting.dashboard') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Översikt
                                </a>
                                <a href="{{ route('admin.accounting.invoices.receivable.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Kundreskontra
                                </a>
                                <a href="{{ route('admin.accounting.invoices.payable.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Leverantörsfakturor
                                </a>
                                <a href="{{ route('admin.accounting.vendors.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Leverantörer
                                </a>
                                <a href="{{ route('admin.accounting.budgets.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Budgetar
                                </a>
                                <a href="{{ route('admin.accounting.journal-entries.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Verifikationer
                                </a>
                                <a href="{{ route('admin.accounting.fiscal-years.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Räkenskapsår
                                </a>
                                <a href="{{ route('admin.accounting.accounts.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Kontoplan
                                </a>
                                <a href="{{ route('admin.accounting.cost-centers.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Kostnadsställen
                                </a>
                                <a href="{{ route('admin.accounting.journal-series.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Verifikationsserier
                                </a>
                                <a href="{{ route('admin.accounting.reports.journal-list') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Rapporter
                                </a>
                                @if (Auth::user()->hasRole('revisor') || Auth::user()->isAdmin())
                                <a href="{{ route('admin.accounting.revision.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Revision
                                </a>
                                @endif
                                @can('accounting.manage')
                                <a href="{{ route('admin.accounting.bank-accounts.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Bankkonton
                                </a>
                                <a href="{{ route('admin.accounting.payment-providers.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Betaltjänster
                                </a>
                                @endcan
                                @endcanany
                                {{-- System --}}
                                @canany(['settings.manage', 'users.manage-roles', 'audit.view', 'org-roles.manage'])
                                <div class="my-1 border-t border-zinc-100 dark:border-zinc-700"></div>
                                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">System</p>
                                @can('org-roles.manage')
                                <a href="{{ route('admin.activities.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Aktiviteter
                                </a>
                                <a href="{{ route('admin.tags.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Taggar
                                </a>
                                @endcan
                                @can('audit.view')
                                <a href="{{ route('admin.audit-log') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Händelselogg
                                </a>
                                @endcan
                                @can('users.manage-roles')
                                <a href="{{ route('admin.roles.index') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Roller
                                </a>
                                @endcan
                                @can('settings.manage')
                                <a href="{{ route('admin.settings') }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Inställningar
                                </a>
                                @endcan
                                @endcanany
                            </div>
                        </div>
                    @endif

                    {{-- Profile dropdown --}}
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button @click="open = !open"
                                class="size-8 overflow-hidden rounded-full ring-2 ring-zinc-200 hover:ring-brand dark:ring-zinc-700 dark:hover:ring-brand"
                                aria-label="Kontomeny">
                            @if(auth()->user()->avatar_path)
                                <img src="{{ auth()->user()->avatarUrl(64) }}" alt="{{ auth()->user()->name }}" class="size-full object-cover">
                            @else
                                <span class="flex size-full items-center justify-center bg-zinc-200 text-xs font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">
                                    {{ collect(explode(' ', auth()->user()->name))->map(fn ($n) => mb_substr($n, 0, 1))->take(2)->implode('') }}
                                </span>
                            @endif
                        </button>
                        <div x-show="open" x-cloak
                             class="absolute right-0 top-full z-50 mt-1 min-w-[200px] overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-700">
                                <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ auth()->user()->name }}</p>
                            </div>
                            <a href="{{ route('dashboard') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Översikt
                            </a>
                            <a href="{{ route('profile.edit') }}" wire:navigate
                               class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Inställningar
                            </a>
                            <hr class="border-zinc-100 dark:border-zinc-700">
                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <button type="submit"
                                        class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    Logga ut
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="flex items-center gap-3">
                        <a href="{{ route('login') }}" wire:navigate class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            Logga in
                        </a>
                        <a href="{{ route('register') }}" wire:navigate class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                            Registrera
                        </a>
                    </div>
                @endauth
            </div>
        </header>

        @include('partials.notice-banner')
        @isset($header){{ $header }}@endisset

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>

        {{-- Global slide-in document viewer — opened via $dispatch('open-document', {...}) --}}
        <x-document-viewer />

        @livewireScripts
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
            }
            if ((window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) && !sessionStorage.getItem('pwa_pinged')) {
                sessionStorage.setItem('pwa_pinged', '1');
                fetch('/pwa-ping', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
            }
        </script>
    </body>
</html>
