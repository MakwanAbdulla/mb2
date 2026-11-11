{{--
    A space's page tree, any depth.
    $byParent: the space's pages grouped by parent_id (WikiSpace::pagesByParent())
    $parentId: the level to draw (null = top)   $currentId: page to highlight
    $reorder:  show sort buttons (calls movePageUp/movePageDown on the component)
--}}
@php $level = $byParent->get($parentId ?? '', collect()); @endphp
@if($level->isNotEmpty())
    <ul @class(['space-y-0.5', 'ml-3 mt-0.5 border-l border-zinc-200 pl-3 dark:border-zinc-700' => $parentId !== null])>
        @foreach($level as $i => $node)
            <li wire:key="tree-{{ $node->id }}">
                <div class="flex items-center gap-2">
                    <a href="{{ route('wiki.page', [$space, $node]) }}" wire:navigate
                       @class([
                           'min-w-0 flex-1 truncate rounded px-1.5 py-1 text-sm transition-colors',
                           'bg-brand/15 font-semibold text-zinc-900 dark:bg-brand/10 dark:text-white' => ($currentId ?? null) === $node->id,
                           'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ($currentId ?? null) !== $node->id,
                       ])>{{ $node->title }}</a>
                    @if($reorder ?? false)
                        <x-sort-buttons prefix="movePage" :id="$node->id" :index="$i" :count="$level->count()" />
                    @endif
                </div>
                @include('livewire.wiki._tree', ['parentId' => $node->id])
            </li>
        @endforeach
    </ul>
@endif
