@props([
    'accounts',          // Collection of Account models (id, code, name)
    'model',             // Wire property path, e.g. "otherAccountId" or "lines.0.account_id"
    'live'       => false, // true → entangle live (like wire:model.live), false → deferred
    'nullable'   => false, // Show a null option at the top
    'nullLabel'  => '– Inget konto –',
    'placeholder'=> 'Välj konto…',
])
@php
    $accList = collect($accounts)->map(fn ($a) => [
        'id'    => (string) data_get($a, 'id'),
        'code'  => data_get($a, 'code'),
        'name'  => data_get($a, 'name'),
        'label' => data_get($a, 'code') . ' – ' . data_get($a, 'name'),
    ])->values();
@endphp
<div
    x-data="{
        open:     false,
        search:   '',
        selId:    $wire.entangle(@js($model)){{ $live ? '.live' : '' }},
        selLabel: '',
        accounts: @js($accList),

        get filtered() {
            const s = this.search.toLowerCase();
            if (!s) return this.accounts;
            return this.accounts.filter(a =>
                (a.code + ' ' + a.name).toLowerCase().includes(s)
            );
        },

        init() {
            const sync = id => {
                const acc = this.accounts.find(a => a.id === String(id ?? ''));
                this.selLabel = acc ? acc.label : '';
            };
            sync(this.selId);
            this.$watch('selId', sync);

            {{--
                Click-outside: the popover is NOT a DOM child of this wrapper,
                so we listen on document and check both the trigger ($el) and
                the popover element ($refs.drop).
            --}}
            this._outside = e => {
                if (this.$el.contains(e.target)) return;
                if (this.$refs.drop?.contains(e.target)) return;
                this.closeDrop();
            };
            document.addEventListener('click', this._outside, true);
            this.$cleanup(() => document.removeEventListener('click', this._outside, true));
        },

        openDrop() {
            const r   = this.$refs.trigger.getBoundingClientRect();
            const w   = Math.max(r.width, 224);
            const drop = this.$refs.drop;
            drop.style.position = 'fixed';
            drop.style.top      = r.bottom + 4 + 'px';
            drop.style.left     = r.left + 'px';
            drop.style.width    = w + 'px';
            drop.showPopover();
            this.open = true;
            this.$nextTick(() => this.$refs.search?.focus());
        },

        closeDrop() {
            try { this.$refs.drop.hidePopover(); } catch (_) {}
            this.open   = false;
            this.search = '';
        },

        pick(id) {
            this.selId = id || null;
            this.closeDrop();
        },
    }"
    x-on:keydown.escape.window="closeDrop()"
    {{ $attributes->only('class') }}
>
    {{-- Trigger button --}}
    <button
        type="button"
        x-ref="trigger"
        @click="open ? closeDrop() : openDrop()"
        class="inp w-full text-left flex items-center justify-between gap-2 pr-2 min-h-[2.25rem]"
        :aria-expanded="open"
    >
        <span
            class="flex-1 truncate text-sm"
            :class="selLabel ? 'text-zinc-900 dark:text-zinc-100' : 'text-zinc-400'"
            x-text="selLabel || @js($placeholder)"
        ></span>
        <x-bootstrap-icon name="chevron-down" class="size-4 shrink-0 text-zinc-400 transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
    </button>

    {{--
        The dropdown uses popover="manual" so the browser promotes it to the
        top layer on showPopover() — above overflow:hidden ancestors AND above
        native <dialog> top-layer elements. Flux already ships a full Popover
        API polyfill, so this works across all supported browsers.

        Browser default [popover] styles (position:fixed, inset:0, margin:auto,
        border, padding) are reset inline; exact placement is set via JS in openDrop().
    --}}
    <div
        x-ref="drop"
        popover="manual"
        style="inset:unset;margin:0;padding:0;border:0;"
        class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 shadow-lg"
    >
        {{-- Search input --}}
        <div class="p-2 border-b border-zinc-100 dark:border-zinc-700">
            <input
                type="text"
                x-ref="search"
                x-model="search"
                placeholder="Sök konto…"
                autocomplete="off"
                class="w-full rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 px-2 py-1 text-sm placeholder-zinc-400 focus:outline-none focus:border-brand dark:focus:border-brand"
            />
        </div>

        {{-- Options list --}}
        <ul class="max-h-56 overflow-y-auto py-1" role="listbox">
            @if ($nullable)
            <li
                @click="pick(null)"
                role="option"
                class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700 text-zinc-400"
                :class="!selId ? 'bg-zinc-50 dark:bg-zinc-700/60' : ''"
            >
                <span>{{ $nullLabel }}</span>
            </li>
            @endif

            <template x-for="acc in filtered" :key="acc.id">
                <li
                    @click="pick(acc.id)"
                    role="option"
                    class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700"
                    :class="acc.id === String(selId ?? '') ? 'bg-brand/10 dark:bg-brand/10' : 'text-zinc-700 dark:text-zinc-200'"
                >
                    <span class="font-mono text-xs text-zinc-400 w-10 shrink-0" x-text="acc.code"></span>
                    <span x-text="acc.name" class="truncate"></span>
                </li>
            </template>

            <li x-show="filtered.length === 0" class="px-3 py-3 text-sm text-zinc-400 text-center italic">
                Inga träffar
            </li>
        </ul>
    </div>
</div>
