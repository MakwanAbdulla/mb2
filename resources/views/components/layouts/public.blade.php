@php
    use App\Models\Content\NavigationItem;
    $navItems = NavigationItem::active()->topnav()->rootItems()->with('children')->get();
    $user     = auth()->user();
    $theme    = $user?->ui_theme ?? 'system';
    $initDark = $theme === 'dark' ? 'dark' : '';
    $useJs    = $theme === 'system' || ! auth()->check();
    $isAdmin       = $user?->isStaff() ?? false;
    $hasFullAccess = $isAdmin || ($user?->member?->hasFullAccess() ?? false);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="{{ $initDark }}">
<head>
    {{-- Before-first-paint: apply theme from localStorage (wins) or server DB value (fallback) --}}
    <script>
        (function(){
            var t = localStorage.getItem('ui_theme') || '{{ $theme }}';
            if (t === 'dark') document.documentElement.classList.add('dark');
            else if (t === 'light') document.documentElement.classList.remove('dark');
            else if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.classList.add('dark');
        })();
    </script>
    {{-- Inline background so page doesn't flash white before Vite CSS loads --}}
    <style>html{background:#fff}html.dark{background:#1c1a1b;color-scheme:dark}</style>
    @include('partials.head')
    {{-- Re-apply after wire:navigate (morph can reset the html class) --}}
    <meta name="ui-theme" content="{{ $theme }}">
    <script>
        document.addEventListener('livewire:navigated', function(){
            var m = document.querySelector('meta[name="ui-theme"]');
            var t = localStorage.getItem('ui_theme') || (m ? m.content : 'system');
            if (t === 'dark') document.documentElement.classList.add('dark');
            else if (t === 'light') document.documentElement.classList.remove('dark');
            else document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);
        });
    </script>
    {{-- Show sidebar immediately on lg before Alpine initialises (avoids flash) --}}
    @auth
        <style>@media (min-width: 1024px) { aside[x-cloak] { display: flex !important; } }</style>
    @endauth
</head>
<body class="flex min-h-screen flex-col bg-white dark:bg-warm-dark"
      x-data="{
          sidebarOpen: false,
          isLg: {{ auth()->check() ? 'true' : 'false' }} && window.matchMedia('(min-width: 1024px)').matches,
          @if(session('kiosk_mode'))
          kioskTimer: null,
          kioskReset() {
              clearTimeout(this.kioskTimer);
              this.kioskTimer = setTimeout(() => {
                  document.getElementById('kiosk-logout-form').submit();
              }, {{ config('app.kiosk_timeout_minutes') }} * 60 * 1000);
          },
          @endif
          init() {
              @auth
              const mq = window.matchMedia('(min-width: 1024px)');
              mq.addEventListener('change', e => {
                  this.isLg = e.matches;
                  if (e.matches) this.sidebarOpen = false;
              });
              @endauth
              @if(session('kiosk_mode'))
              this.kioskReset();
              @endif
          }
      }"
      @keydown.escape.window="sidebarOpen = false"
      @if(session('kiosk_mode'))
      @mousemove.window="kioskReset()"
      @keydown.window="kioskReset()"
      @click.window="kioskReset()"
      @touchstart.window="kioskReset()"
      @endif>

    @if(session('kiosk_mode'))
    <form id="kiosk-logout-form" method="POST" action="{{ route('kiosk.logout') }}" class="hidden">@csrf</form>
    @endif

    {{-- ── Sidebar backdrop (mobile only) ────────────────────────────────────── --}}
    <div x-show="sidebarOpen && !isLg"
         x-cloak
         style="display:none"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm"
         aria-hidden="true"></div>

    {{-- ── Topnav ───────────────────────────────────────────────────────────── --}}
    <header class="sticky top-0 z-30 border-b border-zinc-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:border-zinc-700 dark:bg-zinc-900/95 dark:supports-[backdrop-filter]:bg-zinc-900/80">
        <div @class(['mx-auto flex max-w-6xl items-center gap-3 px-4 sm:px-6', 'h-16 md:h-22' => request()->routeIs('home'), 'h-14' => ! request()->routeIs('home')])>

            {{-- Hamburger: hidden on lg (sidebar is permanent there) --}}
            <button @click="sidebarOpen = !sidebarOpen"
                    class="inline-flex size-9 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white lg:hidden"
                    aria-label="Öppna meny">
                <x-bootstrap-icon name="list" class="size-5" />
            </button>

            {{-- Logo --}}
            <a href="/" class="flex items-center" wire:navigate>
                {{-- Bigger logo on the start page, as in the start page mockup --}}
                @php
                    $logoSize = request()->routeIs('home') ? 'h-8 md:h-13' : 'h-8';
                @endphp
                <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="{{ $logoSize }} dark:hidden">
                <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="{{ config('app.name') }}" class="{{ $logoSize }} hidden dark:block">
            </a>

            {{-- Desktop CMS nav --}}
            <nav class="hidden flex-1 items-center gap-0.5 lg:flex">
                @foreach($navItems as $item)
                    @php /** @var \App\Models\Content\NavigationItem $item */ @endphp
                    @if($item->children->isEmpty())
                        <a href="{{ $item->resolvedUrl() }}" wire:navigate
                           class="rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white
                                  {{ $item->route_name && request()->routeIs($item->route_name) ? 'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-white' : '' }}">
                            {{ $item->label }}
                        </a>
                    @else
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                            <button @click="open = !open"
                                    class="flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
                                {{ $item->label }}
                                <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                            </button>
                            <div x-show="open" x-cloak
                                 class="absolute left-0 top-full z-50 mt-1 min-w-[160px] overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                @foreach($item->children as $child)
                                    @php /** @var \App\Models\Content\NavigationItem $child */ @endphp
                                    <a href="{{ $child->resolvedUrl() }}" wire:navigate
                                       class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                        {{ $child->label }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </nav>

            <div class="flex-1 lg:hidden"></div>

            {{-- Auth actions --}}
            @auth
                @if($user->member)
                <a href="{{ route('member.card') }}"
                   wire:navigate
                   class="lg:hidden inline-flex size-9 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
                   aria-label="Mitt medlemskort">
                    <x-bootstrap-icon name="qr-code" class="size-5" />
                </a>
                @endif

                @livewire('theme-toggle')

                <livewire:shared.notification-bell wire:poll.60s />

                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                            class="size-8 overflow-hidden rounded-full ring-2 ring-zinc-200 hover:ring-brand dark:ring-zinc-700 dark:hover:ring-brand"
                            aria-label="Kontomeny">
                        @if($user->avatar_path)
                            <img src="{{ $user->avatarUrl(64) }}" alt="{{ $user->name }}" class="size-full object-cover">
                        @else
                            <span class="flex size-full items-center justify-center bg-zinc-200 text-xs font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">
                                {{ collect(explode(' ', $user->name))->map(fn ($n) => mb_substr($n, 0, 1))->take(2)->implode('') }}
                            </span>
                        @endif
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute right-0 top-full z-50 mt-1 min-w-[200px] overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-700">
                            <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $user->name }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</p>
                        </div>
                        <a href="{{ route('dashboard') }}" wire:navigate
                           class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            <x-bootstrap-icon name="house" class="size-3.5 shrink-0 text-zinc-400" />
                            Armagedon &amp; jag
                        </a>
                        <a href="{{ route('dashboard') }}#medlemskap" wire:navigate
                           class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            <x-bootstrap-icon name="person-badge" class="size-3.5 shrink-0 text-zinc-400" />
                            Mitt medlemskap
                        </a>
                        @if($user->member)
                        <a href="{{ route('member.card') }}" wire:navigate
                           class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            <x-bootstrap-icon name="qr-code" class="size-3.5 shrink-0 text-zinc-400" />
                            Mitt medlemskort
                        </a>
                        @endif
                        <a href="{{ route('dashboard') }}#installningar" wire:navigate
                           class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            <x-bootstrap-icon name="gear" class="size-3.5 shrink-0 text-zinc-400" />
                            Inställningar &amp; profilbild
                        </a>
                        @php $userMailbox = \App\Models\InboxMailbox::forOwner($user); @endphp
                        @if($userMailbox)
                        <a href="{{ route('my-inbox') }}" wire:navigate
                           class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            <x-bootstrap-icon name="inbox" class="size-3.5 shrink-0 text-zinc-400" />
                            Min inkorg
                            <livewire:shared.inbox-unread-badge :owner-id="$userMailbox->id" wire:poll.60s />
                        </a>
                        @endif
                        @php
                            $wsEmail = $user->member?->workspaceAccount?->email;
                            $delegatedMailboxes = $wsEmail
                                ? \App\Models\WorkspaceDelegate::where('delegate_email', $wsEmail)
                                    ->where('status', 'accepted')
                                    ->pluck('mailbox')
                                : collect();
                        @endphp
                        @if($delegatedMailboxes->isNotEmpty())
                            <hr class="border-zinc-100 dark:border-zinc-700">
                            <div class="px-4 py-1.5">
                                <p class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Postlådor</p>
                            </div>
                            @foreach($delegatedMailboxes as $mailbox)
                                <a href="https://mail.google.com/mail/b/{{ $mailbox }}/"
                                   target="_blank" rel="noopener"
                                   class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    <x-bootstrap-icon name="envelope" class="size-3.5 shrink-0 text-zinc-400" />
                                    {{ $mailbox }}
                                </a>
                            @endforeach
                        @endif
                        <hr class="border-zinc-100 dark:border-zinc-700">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                <x-bootstrap-icon name="box-arrow-right" class="size-3.5 shrink-0 text-zinc-400" />
                                Logga ut
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="flex items-center gap-3">
                    <a href="{{ route('login') }}" wire:navigate
                       class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                        Logga in
                    </a>
                    <a href="{{ route('membership.apply') }}" wire:navigate
                       class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                        Bli medlem
                    </a>
                </div>
            @endauth
        </div>
        @include('partials.notice-banner')
    </header>

    {{-- ── Content area (sidebar + main) ───────────────────────────────────── --}}
    <div class="flex flex-1">

        {{-- ── Sidebar ─────────────────────────────────────────────────────── --}}
        <aside x-show="sidebarOpen || isLg"
               x-cloak
               :class="isLg
                   ? 'sticky top-14 h-[calc(100vh-3.5rem)] border-r border-zinc-200 dark:border-zinc-700 shadow-none'
                   : 'fixed inset-y-0 left-0 z-50 shadow-xl'"
               class="flex w-72 shrink-0 flex-col overflow-y-auto bg-white dark:bg-zinc-900"
               aria-label="Sidomeny">

            {{-- Close button: only needed on mobile --}}
            <div class="flex h-14 shrink-0 items-center justify-end border-b border-zinc-200 px-4 dark:border-zinc-700 lg:hidden">
                <button @click="sidebarOpen = false"
                        class="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                        aria-label="Stäng meny">
                    <x-bootstrap-icon name="x-lg" class="size-5" />
                </button>
            </div>

            {{-- Sidebar nav --}}
            @php
                $inAdminSection = request()->routeIs('admin.*', 'booking.admin.*');
                $inJagSection   = request()->routeIs('dashboard', 'membership.*', 'profile.*');
                $defaultMode    = $isAdmin && $inAdminSection ? 'admin' : 'member';
            @endphp
            <nav class="flex-1 overflow-y-auto px-3 py-4"
                 x-data="{
                     mode: $persist('{{ $defaultMode }}').as('sidebar_mode'),
                     jag: true,
                     bokning: true,
                     foreningen: true,
                     adminForeningen: true,
                     evenemang: true,
                     web: true,
                     setMode(m) { this.mode = m; }
                 }">

                @auth

                    @if($isAdmin)
                        {{-- Mode toggle --}}
                        <div class="mb-4 flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                            <button @click="setMode('member')"
                                    :class="mode === 'member'
                                        ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                                    class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-all">
                                <x-bootstrap-icon name="person" class="size-3.5" />
                                Jag
                            </button>
                            <button @click="setMode('admin')"
                                    :class="mode === 'admin'
                                        ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                                    class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-all">
                                <x-bootstrap-icon name="shield-check" class="size-3.5" />
                                Admin
                            </button>
                        </div>
                    @endif

                    <div class="space-y-6">

                    {{-- Armagedon & jag --}}
                    <div x-show="mode === 'member'">
                        <button @click="jag = !jag"
                                class="flex w-full items-center justify-between px-2 py-1 text-xs font-semibold uppercase tracking-widest text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                            Armagedon &amp; jag
                            <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform duration-150" x-bind:class="{ '-rotate-90': !jag }" />
                        </button>
                        <div x-show="jag"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 translate-y-0"
                             x-transition:leave-end="opacity-0 -translate-y-1"
                             class="mt-1 space-y-0.5">
                            <x-sidebar-link href="{{ route('dashboard') }}" icon="house" :active="request()->routeIs('dashboard')" @click="sidebarOpen = false">
                                Min sida
                            </x-sidebar-link>
                        </div>
                    </div>

                    {{-- Lokal & bokning --}}
                    <div x-show="mode === 'member'">
                        <button @click="bokning = !bokning"
                                class="flex w-full items-center justify-between px-2 py-1 text-xs font-semibold uppercase tracking-widest text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                            Lokal &amp; bokning
                            <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform duration-150" x-bind:class="{ '-rotate-90': !bokning }" />
                        </button>
                        <div x-show="bokning"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 translate-y-0"
                             x-transition:leave-end="opacity-0 -translate-y-1"
                             class="mt-1 space-y-0.5">
                            <x-sidebar-link href="{{ route('booking.calendar') }}" icon="calendar3" :active="request()->routeIs('booking.calendar')" @click="sidebarOpen = false">
                                Kalender
                            </x-sidebar-link>
                            @if($hasFullAccess)
                                <x-sidebar-link href="{{ route('booking.create') }}" icon="plus-circle" :active="request()->routeIs('booking.create')" @click="sidebarOpen = false">
                                    Boka lokal
                                </x-sidebar-link>
                                <x-sidebar-link href="{{ route('booking.my') }}" icon="calendar-check" :active="request()->routeIs('booking.my')" @click="sidebarOpen = false">
                                    Mina bokningar
                                </x-sidebar-link>
                                <x-sidebar-link href="{{ route('booking.venue-application.create') }}" icon="building-add" :active="request()->routeIs('booking.venue-application.create')" @click="sidebarOpen = false">
                                    Ansök om lokal
                                </x-sidebar-link>
                            @else
                                <span class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-zinc-300 dark:text-zinc-600 cursor-not-allowed select-none">
                                    <x-bootstrap-icon name="lock" class="size-4 shrink-0" />
                                    Boka lokal
                                </span>
                            @endif
                            <x-sidebar-link href="{{ route('shop.my-orders') }}" icon="bag" :active="request()->routeIs('shop.my-orders')" @click="sidebarOpen = false">
                                Mina beställningar
                            </x-sidebar-link>
                        </div>
                    </div>

                    {{-- Evenemang --}}
                    <div x-show="mode === 'member'">
                        <button @click="evenemang = !evenemang"
                                class="flex w-full items-center justify-between px-2 py-1 text-xs font-semibold uppercase tracking-widest text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                            Evenemang
                            <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform duration-150" x-bind:class="{ '-rotate-90': !evenemang }" />
                        </button>
                        <div x-show="evenemang"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 translate-y-0"
                             x-transition:leave-end="opacity-0 -translate-y-1"
                             class="mt-1 space-y-0.5">
                            <x-sidebar-link href="{{ route('events.index') }}" icon="calendar-event" :active="request()->routeIs('events.index', 'events.show')" @click="sidebarOpen = false">
                                Alla evenemang
                            </x-sidebar-link>
                            <x-sidebar-link href="{{ route('events.calendar') }}" icon="calendar3" :active="request()->routeIs('events.calendar')" @click="sidebarOpen = false">
                                Kalender
                            </x-sidebar-link>
                            <x-sidebar-link href="{{ route('events.my-registrations') }}" icon="ticket" :active="request()->routeIs('events.my-registrations')" @click="sidebarOpen = false">
                                Mina anmälningar
                            </x-sidebar-link>
                        </div>
                    </div>

                    {{-- Hjälp --}}
                    <div x-show="mode === 'member'">
                        <x-sidebar-link href="{{ route('help') }}" icon="question-circle" :active="request()->routeIs('help')" @click="sidebarOpen = false">
                            Hjälp
                        </x-sidebar-link>
                    </div>

                    {{-- Topplistan --}}
                    @auth
                    <div x-show="mode === 'member'">
                        <x-sidebar-link href="{{ route('gamification.leaderboard') }}" icon="bar-chart-steps" :active="request()->routeIs('gamification.*')" @click="sidebarOpen = false">
                            Topplistan
                        </x-sidebar-link>
                    </div>
                    <div x-show="mode === 'member'">
                        <x-sidebar-link href="{{ route('tournaments.index') }}" icon="trophy" :active="request()->routeIs('tournaments.*')" @click="sidebarOpen = false">
                            Turneringar
                        </x-sidebar-link>
                    </div>
                    @endauth

                    {{-- Föreningen --}}
                    <div x-show="mode === 'member'">
                        <button @click="foreningen = !foreningen"
                                class="flex w-full items-center justify-between px-2 py-1 text-xs font-semibold uppercase tracking-widest text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                            Föreningen
                            <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform duration-150" x-bind:class="{ '-rotate-90': !foreningen }" />
                        </button>
                        <div x-show="foreningen"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 translate-y-0"
                             x-transition:leave-end="opacity-0 -translate-y-1"
                             class="mt-1 space-y-0.5">
                            <x-sidebar-link href="{{ route('groups.index') }}" icon="diagram-3" :active="request()->routeIs('groups.*')" @click="sidebarOpen = false">
                                Grupper
                            </x-sidebar-link>
                            {{-- For every logged-in user: /wiki says when there is nothing to read, so no page pays for an access lookup. --}}
                            <x-sidebar-link href="{{ route('wiki.index') }}" icon="journal-text" :active="request()->routeIs('wiki.*')" @click="sidebarOpen = false">
                                Wiki
                            </x-sidebar-link>
                            @if($hasFullAccess)
                                <x-sidebar-link href="{{ route('dashboard') }}#hyror" icon="lock" :active="false" @click="sidebarOpen = false">
                                    Mina hyror
                                </x-sidebar-link>
                            @endif
                        </div>
                    </div>

                    @if($isAdmin)
                        {{-- Admin: Föreningen --}}
                        @if($user->hasAnyPermission(['members.view', 'members.edit', 'members.manage', 'membership-periods.manage', 'sections.manage', 'org-roles.manage', 'rentals.manage', 'workspace.manage']))
                        <div x-show="mode === 'admin'">
                            <button @click="adminForeningen = !adminForeningen"
                                    class="flex w-full items-center justify-between px-2 py-1 text-xs font-semibold uppercase tracking-widest text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                                Föreningen
                                <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform duration-150" x-bind:class="{ '-rotate-90': !adminForeningen }" />
                            </button>
                            <div x-show="adminForeningen"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 -translate-y-1"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100 translate-y-0"
                                 x-transition:leave-end="opacity-0 -translate-y-1"
                                 class="mt-1 space-y-0.5">
                                @can('members.view')
                                <x-sidebar-link href="{{ route('admin.members.index') }}" icon="people" :active="request()->routeIs('admin.members.*', 'admin.family-groups.*')" @click="sidebarOpen = false" permission="members.view">
                                    Medlemmar
                                </x-sidebar-link>
                                @endcan
                                @can('sections.manage')
                                <x-sidebar-link href="{{ route('admin.sections.index') }}" icon="diagram-3" :active="request()->routeIs('admin.sections.*')" @click="sidebarOpen = false" permission="sections.manage">
                                    Grupper
                                </x-sidebar-link>
                                @endcan
                                @can('org-roles.manage')
                                <x-sidebar-link href="{{ route('admin.org-roles.index') }}" icon="person-badge" :active="request()->routeIs('admin.org-roles.*')" @click="sidebarOpen = false" permission="org-roles.manage">
                                    Org-roller
                                </x-sidebar-link>
                                @endcan
                                @can('membership-periods.manage')
                                <x-sidebar-link href="{{ route('admin.membership-fees.index') }}" icon="cash-coin" :active="request()->routeIs('admin.membership-fees.*', 'admin.rentals.prices')" @click="sidebarOpen = false" permission="membership-periods.manage">
                                    Avgifter
                                </x-sidebar-link>
                                @endcan
                                @can('rentals.manage')
                                <x-sidebar-link href="{{ route('admin.rentals.index') }}" icon="key" :active="request()->routeIs('admin.rentals.*', 'admin.rfid-tags.*')" @click="sidebarOpen = false" permission="rentals.manage">
                                    Skåp/Taggar/Nycklar
                                </x-sidebar-link>
                                @endcan
                                @can('payments.view')
                                <x-sidebar-link href="{{ route('admin.payments.index') }}" icon="credit-card" :active="request()->routeIs('admin.payments.*')" @click="sidebarOpen = false" permission="payments.view">
                                    Betalningar
                                </x-sidebar-link>
                                @endcan
                                @can('workspace.manage')
                                @if(\App\Models\InboxMailbox::forPurpose(\App\Models\InboxMailbox::PURPOSE_OFFICE))
                                <x-sidebar-link href="{{ route('admin.office-inbox') }}" icon="inbox" :active="request()->routeIs('admin.office-inbox')" @click="sidebarOpen = false" permission="workspace.manage">
                                    Kansliinkorg
                                    <livewire:shared.inbox-unread-badge purpose="office" wire:poll.60s />
                                </x-sidebar-link>
                                @endif
                                @endcan
                            </div>
                        </div>
                        @endif

                        {{-- Admin: moduler (kompakta enkellänkar) --}}
                        <div x-show="mode === 'admin'" class="space-y-0.5">
                            @if($user->hasAnyPermission(['bookings.manage', 'bookings.categories', 'bookings.applications', 'bookings.super']))
                            <x-sidebar-link href="{{ route('booking.admin.bookings.index') }}" icon="calendar-check" :active="request()->routeIs('booking.admin.*')" @click="sidebarOpen = false" permission="bookings.manage">
                                Bokningar
                            </x-sidebar-link>
                            @endif
                            @if($user->hasAnyPermission(['events.view', 'events.manage-own', 'events.manage-all', 'payments.view']))
                            <x-sidebar-link href="{{ route('admin.events.index') }}" icon="calendar-event" :active="request()->routeIs('admin.events.*', 'admin.discounts.*')" @click="sidebarOpen = false" permission="events.view">
                                Evenemang
                            </x-sidebar-link>
                            @endif
                            @if($user->hasAnyPermission(['meetings.view', 'meetings.manage', 'tasks.view', 'submissions.view']))
                            <x-sidebar-link href="{{ route('admin.meetings.index') }}" icon="people-fill" :active="request()->routeIs('admin.meetings.*', 'admin.tasks.*', 'admin.submissions.*')" @click="sidebarOpen = false" permission="meetings.view">
                                Möten
                            </x-sidebar-link>
                            @endif
                            @canany(['signing.view', 'signing.create'])
                            <x-sidebar-link href="{{ route('admin.signing.index') }}" icon="pen" :active="request()->routeIs('admin.signing.*')" @click="sidebarOpen = false" permission="signing.view">
                                E-signering
                            </x-sidebar-link>
                            @endcanany
                            @can('shop.manage')
                            <x-sidebar-link href="{{ route('admin.shop.products.index') }}" icon="bag" :active="request()->routeIs('admin.shop.*')" @click="sidebarOpen = false" permission="shop.manage">
                                Webshop
                            </x-sidebar-link>
                            @endcan
                            @if($user->hasAnyPermission(['accounting.view', 'accounting.manage', 'accounting.admin', 'accounting.approve']))
                            <x-sidebar-link href="{{ route('admin.accounting.dashboard') }}" icon="calculator" :active="request()->routeIs('admin.accounting.*')" @click="sidebarOpen = false" permission="accounting.view">
                                Bokföring
                            </x-sidebar-link>
                            @endif
                            @can('forms.manage')
                            <x-sidebar-link href="{{ route('admin.forms.index') }}" icon="ui-checks" :active="request()->routeIs('admin.forms.*')" @click="sidebarOpen = false" permission="forms.manage">
                                Formulär
                            </x-sidebar-link>
                            @endcan
                            @if($user->hasAnyPermission(['mailings.manage', 'surveys.manage']))
                            <x-sidebar-link href="{{ $user->can('mailings.manage') ? route('admin.mailings.index') : route('admin.surveys.index') }}" icon="envelope" :active="request()->routeIs('admin.mailings.*', 'admin.mailing-templates.*', 'admin.surveys.*')" @click="sidebarOpen = false" :permission="$user->can('mailings.manage') ? 'mailings.manage' : 'surveys.manage'">
                                Utskick
                            </x-sidebar-link>
                            @endif
                            @can('cms.edit')
                            <x-sidebar-link href="{{ route('admin.pages.index') }}" icon="globe" :active="request()->routeIs('admin.pages.*', 'admin.navigation.*', 'admin.help-texts.*', 'admin.help-sections.*', 'admin.contact.*', 'admin.notices.*')" @click="sidebarOpen = false" permission="cms.edit">
                                Webb
                            </x-sidebar-link>
                            @endcan
                            @can('statistics.view')
                            <x-sidebar-link href="{{ route('admin.statistics.members') }}" icon="bar-chart" :active="request()->routeIs('admin.statistics.*')" @click="sidebarOpen = false" permission="statistics.view">
                                Statistik
                            </x-sidebar-link>
                            @endcan
                            @can('tournaments.manage')
                            <x-sidebar-link href="{{ route('admin.tournaments.index') }}" icon="trophy" :active="request()->routeIs('admin.tournaments.*')" @click="sidebarOpen = false" permission="tournaments.manage">
                                Turneringar
                            </x-sidebar-link>
                            @endcan
                            @can('wiki.manage')
                            <x-sidebar-link href="{{ route('admin.wiki.index') }}" icon="journal-text" :active="request()->routeIs('admin.wiki.*')" @click="sidebarOpen = false" permission="wiki.manage">
                                Wiki
                            </x-sidebar-link>
                            @endcan
                            @can('gamification.manage')
                            <x-sidebar-link href="{{ route('admin.gamification.index') }}" icon="bar-chart-steps" :active="request()->routeIs('admin.gamification.*')" @click="sidebarOpen = false" permission="gamification.manage">
                                Gamification
                            </x-sidebar-link>
                            @endcan
                            @if($user->hasAnyPermission(['settings.manage', 'users.manage-roles', 'audit.view']) || $isAdmin)
                            <x-sidebar-link href="{{ route('admin.settings') }}?tab=allmant" icon="gear" :active="request()->routeIs('admin.settings', 'admin.tags.*', 'admin.activities.*', 'admin.roles.*', 'admin.audit-log')" @click="sidebarOpen = false">
                                System
                            </x-sidebar-link>
                            @endif
                            @can('workspace.manage')
                            <x-sidebar-link href="{{ route('admin.workspace.index') }}" icon="google" :active="request()->routeIs('admin.workspace.*')" @click="sidebarOpen = false">
                                Workspace
                            </x-sidebar-link>
                            @endcan
                            @can('settings.manage')
                            <x-sidebar-link href="{{ route('admin.api-clients.index') }}" icon="plug" :active="request()->routeIs('admin.api-clients.*')" @click="sidebarOpen = false">
                                API-klienter
                            </x-sidebar-link>
                            @endcan
                            @if($isAdmin)
                            <x-sidebar-link href="{{ route('api-docs') }}" icon="braces" :active="request()->routeIs('api-docs')" @click="sidebarOpen = false" target="_blank">
                                API-dokumentation
                            </x-sidebar-link>
                            @endif
                        </div>
                    @endif

                    {{-- CMS nav på mobil --}}
                    @if($navItems->isNotEmpty())
                        <div class="lg:hidden border-t border-zinc-100 pt-4 dark:border-zinc-800">
                            <button @click="web = !web"
                                    class="flex w-full items-center justify-between px-2 py-1 text-xs font-semibold uppercase tracking-widest text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                                Webbplatsen
                                <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform duration-150" x-bind:class="{ '-rotate-90': !web }" />
                            </button>
                            <div x-show="web"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 -translate-y-1"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100 translate-y-0"
                                 x-transition:leave-end="opacity-0 -translate-y-1"
                                 class="mt-1 space-y-0.5">
                                @foreach($navItems as $item)
                                    @php /** @var \App\Models\Content\NavigationItem $item */ @endphp
                                    <a href="{{ $item->resolvedUrl() }}" wire:navigate @click="sidebarOpen = false"
                                       class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                        {{ $item->label }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    </div>{{-- end space-y-6 --}}

                @else
                    {{-- Gäst: bara CMS-nav (mobil) --}}
                    <div>
                        <div class="space-y-0.5">
                            @foreach($navItems as $item)
                                @php /** @var \App\Models\Content\NavigationItem $item */ @endphp
                                <a href="{{ $item->resolvedUrl() }}" wire:navigate @click="sidebarOpen = false"
                                   class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                    {{ $item->label }}
                                </a>
                                @if($item->children->isNotEmpty())
                                    @foreach($item->children as $child)
                                        <a href="{{ $child->resolvedUrl() }}" wire:navigate @click="sidebarOpen = false"
                                           class="flex items-center gap-2.5 rounded-lg py-2 pl-7 pr-3 text-sm text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                            {{ $child->label }}
                                        </a>
                                    @endforeach
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endauth

            </nav>

            {{-- Sidebar footer --}}
            <div class="shrink-0 border-t border-zinc-200 p-3 dark:border-zinc-700">
                @auth
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                            <x-bootstrap-icon name="box-arrow-left" class="size-4 shrink-0" />
                            Logga ut
                        </button>
                    </form>
                @else
                    <div class="space-y-1">
                        <a href="{{ route('login') }}" wire:navigate @click="sidebarOpen = false"
                           class="flex w-full items-center justify-center rounded-lg px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                            Logga in
                        </a>
                        <a href="{{ route('membership.apply') }}" wire:navigate @click="sidebarOpen = false"
                           class="flex w-full items-center justify-center rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                            Bli medlem
                        </a>
                    </div>
                @endauth
            </div>
        </aside>

        {{-- ── Page content ─────────────────────────────────────────────────── --}}
        <div class="flex min-w-0 flex-1 flex-col">
            @if(request()->routeIs('admin.accounting.*'))
                <x-accounting-nav />
            @elseif(request()->routeIs('admin.rentals.*', 'admin.rfid-tags.*'))
                <x-rentals-nav />
            @elseif(request()->routeIs('admin.membership-fees.*'))
                <x-avgifter-nav />
            @elseif(request()->routeIs('admin.shop.*'))
                <x-webshop-nav />
            @elseif(request()->routeIs('admin.pages.*', 'admin.navigation.*', 'admin.help-texts.*', 'admin.help-sections.*', 'admin.contact.*', 'admin.notices.*'))
                <x-webb-nav />
            @elseif(request()->routeIs('admin.settings', 'admin.tags.*', 'admin.activities.*', 'admin.roles.*', 'admin.audit-log'))
                <x-system-nav />
            @elseif(request()->routeIs('admin.workspace.*'))
                <x-workspace-nav />
            @elseif(request()->routeIs('admin.mailings.*', 'admin.mailing-templates.*', 'admin.surveys.*'))
                <x-utskick-nav />
            @elseif(request()->routeIs('admin.events.*', 'admin.discounts.*'))
                <x-events-nav />
            @elseif(request()->routeIs('admin.meetings.*', 'admin.tasks.*', 'admin.submissions.*'))
                <x-moten-nav />
            @elseif(request()->routeIs('admin.tournaments.*'))
                <x-tournaments-nav />
            @elseif(request()->routeIs('admin.gamification.*'))
                <x-gamification-nav />
            @elseif(request()->routeIs('admin.members.*', 'admin.family-groups.*'))
                <x-members-nav />
            @elseif(request()->routeIs('admin.statistics.*'))
                <x-statistics-nav />
            @elseif(request()->routeIs('booking.admin.*'))
                <x-bokningar-nav />
            @else
                @isset($header){{ $header }}@endisset
            @endif
            <main class="min-w-0 flex-1">
                {{ $slot }}
            </main>
        </div>

    </div>{{-- end flex content area --}}

    {{-- ── Footer ──────────────────────────────────────────────────────────── --}}
    <footer class="border-t border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            <div class="grid gap-8 md:grid-cols-3">
                <div>
                    <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="mb-3 h-8 dark:hidden">
                    <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="{{ config('app.name') }}" class="mb-3 h-8 hidden dark:block">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ help_text('footer.description') }}
                    </p>
                </div>

                <div>
                    <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Länkar</h3>
                    <ul class="space-y-2 text-sm">
                        @for ($i = 1; $i <= 6; $i++)
                            @php
                                $linkText = help_text("footer.link{$i}");
                                $linkUrl  = help_text("footer.link{$i}", 'url');
                                $isExt    = str_starts_with($linkUrl, 'http');
                            @endphp
                            @if($linkText !== '')
                                <li>
                                    @if($isExt)
                                        <a href="{{ $linkUrl }}" target="_blank" rel="noopener noreferrer"
                                           class="inline-flex items-center gap-1.5 text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                                            {{ $linkText }}
                                            <x-bootstrap-icon name="box-arrow-up-right" class="size-3 opacity-60" />
                                        </a>
                                    @else
                                        <a href="{{ $linkUrl ? url($linkUrl) : '#' }}" wire:navigate
                                           class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                                            {{ $linkText }}
                                        </a>
                                    @endif
                                </li>
                            @endif
                        @endfor
                    </ul>
                </div>

                <div>
                    <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Sociala medier</h3>
                    <ul class="space-y-2 text-sm">
                        @for ($i = 1; $i <= 6; $i++)
                            @php
                                $socialText = help_text("footer.social{$i}");
                                $socialUrl  = help_text("footer.social{$i}", 'url');
                                $isExt      = str_starts_with($socialUrl, 'http');
                            @endphp
                            @if($socialText !== '')
                                <li>
                                    @if($isExt)
                                        <a href="{{ $socialUrl }}" target="_blank" rel="noopener noreferrer"
                                           class="inline-flex items-center gap-1.5 text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                                            {{ $socialText }}
                                            <x-bootstrap-icon name="box-arrow-up-right" class="size-3 opacity-60" />
                                        </a>
                                    @else
                                        <a href="{{ $socialUrl ? url($socialUrl) : '#' }}" wire:navigate
                                           class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                                            {{ $socialText }}
                                        </a>
                                    @endif
                                </li>
                            @endif
                        @endfor
                    </ul>
                </div>
            </div>

            <div class="mt-8 border-t border-zinc-200 pt-6 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-500">
                &copy; {{ date('Y') }} {{ config('app.name') }}.
            </div>
        </div>
    </footer>

    @fluxScripts
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
        }
        if ((window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) && !sessionStorage.getItem('pwa_pinged')) {
            sessionStorage.setItem('pwa_pinged', '1');
            fetch('/pwa-ping', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
        }
    </script>
    @stack('scripts')
</body>
</html>
