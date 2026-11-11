{{--
    Up/down buttons for lists with sort_order.
    Calls {prefix}Up($id) / {prefix}Down($id) on the parent Livewire component.

    <x-sort-buttons prefix="moveItem" :id="$item->id" :index="$i" :count="count($items)" />
--}}
@props([
    'prefix' => 'move',
    'id',
    'index',
    'count',
    'vertical' => false,
])

<div {{ $attributes->class(['flex shrink-0 items-center gap-0.5', 'flex-col' => $vertical]) }}>
    <flux:button
        size="xs"
        variant="ghost"
        icon="chevron-up"
        wire:click="{{ $prefix }}Up({{ $id }})"
        :disabled="$index === 0"
        class="disabled:opacity-30!"
        aria-label="Flytta upp"
    />
    <flux:button
        size="xs"
        variant="ghost"
        icon="chevron-down"
        wire:click="{{ $prefix }}Down({{ $id }})"
        :disabled="$index >= $count - 1"
        class="disabled:opacity-30!"
        aria-label="Flytta ned"
    />
</div>
