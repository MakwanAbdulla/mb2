# UI Patterns

## Collection pages with sub-navigation

A "collection page" is any page that groups multiple related sections under one URL — "Min sida", Bokföring, Evenemang admin, etc. Use a **wrapping pill nav** for the secondary navigation. Never use a horizontal scrolling tab bar (`overflow-x-auto`).

### Why wrapping pills

- A fixed single-row bar overflows on small screens (and on large screens when the global sidebar is visible).
- Pills wrap to a second line automatically — no horizontal scroll, no overflow.
- `border-b-2` active indicators require a single row and break visually when items wrap. Filled background indicators work at any wrap point.

---

## Pattern A — Hash-based single page

Use when all sections live on one URL and are toggled client-side (e.g. `/dashboard`).

### Blade template structure

```blade
<div x-data="{
    tab: window.location.hash.slice(1) || 'oversikt',
    setTab(t) { this.tab = t; history.replaceState(null, '', '#' + t); }
}"
x-init="
    window.addEventListener('hashchange', () => { tab = window.location.hash.slice(1) || 'oversikt'; });
    window.addEventListener('set-tab', e => setTab(e.detail));
">
    {{-- Sticky pill nav --}}
    <div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
         style="top: var(--header-height, 3.5rem)">
        <div class="mx-auto max-w-4xl px-4 py-2.5 sm:px-6">
            <nav class="flex flex-wrap gap-1.5">

                <button @click="setTab('oversikt')"
                        :class="tab === 'oversikt'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="house" class="size-4 shrink-0" />
                    Översikt
                </button>

                {{-- repeat for each section --}}

            </nav>
        </div>
    </div>

    {{-- Content panels --}}
    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6">
        <div x-show="tab === 'oversikt'">
            @livewire('module.oversikt')
        </div>
        {{-- repeat for each section --}}
    </div>
</div>
```

### Rules

- Default tab is the first section (e.g. `'oversikt'`).
- Tabs gated by permissions or membership are wrapped in `@if($this->canSeeX)`.
- Tab names omit possessives: **Grupper** not "Mina grupper", **Familj** not "Min familj".
- Deep-link from elsewhere with `route('dashboard') . '#sektionsnamn'`.
- To switch tabs programmatically from any JS context: `window.dispatchEvent(new CustomEvent('set-tab', { detail: 'sektionsnamn' }))`.

---

## Pattern B — Route-based multi-page module

Use when each section has its own URL (e.g. `/admin/bokforing/*`). The nav is a Blade component injected by the layout.

### Create `resources/views/components/module-nav.blade.php`

```blade
@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);
    $activeTab = match(true) {
        $is('admin.module.oversikt')    => 'oversikt',
        $is('admin.module.sektionA.*')  => 'sektionA',
        $is('admin.module.sektionB.*')  => 'sektionB',
        default                         => '',
    };
    $tab = fn(string $key) => $activeTab === $key;
@endphp

<div class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
    <div class="px-4 sm:px-6">
        <nav class="flex flex-wrap gap-1.5 py-2.5">

            <a href="{{ route('admin.module.oversikt') }}" wire:navigate
               @class([
                   'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                   'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white' => $tab('oversikt'),
                   'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200' => !$tab('oversikt'),
               ])>
                <x-bootstrap-icon name="house" class="size-4 shrink-0" />
                Översikt
            </a>

            {{-- For groups with sub-pages, use a dropdown trigger (same pill style) --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button @click="open = !open"
                        @class([
                            'flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                            'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white' => $tab('sektionA'),
                            'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200' => !$tab('sektionA'),
                        ])>
                    Sektion A
                    <x-bootstrap-icon name="chevron-down" class="size-3.5 transition-transform duration-150" x-bind:class="{ 'rotate-180': open }" />
                </button>
                <div x-show="open" x-cloak
                     class="absolute left-0 top-full z-50 mt-1 min-w-[180px] overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                    <a href="{{ route('admin.module.sektionA.sub1') }}" wire:navigate @click="open = false"
                       @class(['flex items-center gap-2 px-4 py-2 text-sm',
                           'bg-zinc-50 font-medium text-zinc-900 dark:bg-zinc-700 dark:text-white' => $is('admin.module.sektionA.sub1'),
                           'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700' => !$is('admin.module.sektionA.sub1'),
                       ])>
                        Undersektion 1
                    </a>
                </div>
            </div>

        </nav>
    </div>
</div>
```

### Inject the nav component in the layout

In `resources/views/components/layouts/public.blade.php`, after the existing accounting block:

```blade
@if(request()->routeIs('admin.accounting.*'))
    <x-accounting-nav />
@endif
@if(request()->routeIs('admin.module.*'))
    <x-module-nav />
@endif
```

### Rules

- Active state uses the same filled-background pill style as Pattern A.
- Use a `match(true)` block for `$activeTab` — groups multiple routes under one tab key.
- Dropdowns for groups follow the same pattern as the top nav dropdowns in the global header.
- **Do not** use `border-b-2 border-brand` tabs (horizontal scroll risk, breaks on wrap).

---

## The `$embedded` prop

Any Livewire/Volt component that can be used **both** as a standalone page and as an embedded tab must declare an `$embedded` prop. The prop suppresses slot fills that would conflict with the parent layout.

### In the component (Volt)

```php
new class extends Component {
    public bool $embedded = false;
    // ...
};
```

### In the template

```blade
<div>
    @if(!$embedded)
    <x-slot:header>
        <x-page-header title="Rubrik" />
        {{-- or any header-area content --}}
    </x-slot:header>
    @endif

    {{-- main content always rendered --}}
</div>
```

When `$embedded` is true, actions that would normally live in the header (e.g. a "Skicka in" button) should be moved inline at the top of the component body using an `@else` branch.

### When embedding

```blade
@livewire('member.expense-claims.index', ['embedded' => true])
```

### When to apply

Apply `$embedded` whenever a component uses `<x-slot:header>`. A component that only renders content (no slot fills) can be embedded without any changes.

---

## Modules to migrate to pill nav

| Module | Current nav style | Status |
|---|---|---|
| Min sida (`/dashboard`) | Wrapping pills | Done |
| Bokföring (`/admin/bokforing/*`) | `<x-accounting-nav>` med modulheader + sticky pillnav | Done |
