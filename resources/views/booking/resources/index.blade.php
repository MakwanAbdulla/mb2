<x-layouts.public title="Rum &amp; lokaler">
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 space-y-8">

    <h1 class="text-3xl font-bold text-zinc-900 dark:text-white">Rum &amp; lokaler</h1>

    @foreach ($categories as $cat)
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <span class="inline-block h-3 w-3 rounded-full" style="background-color: {{ $cat->color }}"></span>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $cat->name }}</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($cat->resources as $resource)
                    @php $data = $bookingData[$resource->id] ?? ['current' => null, 'upcoming' => collect()]; @endphp
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">
                        @if ($resource->color)
                            <div class="h-1.5" style="background-color: {{ $resource->color }}"></div>
                        @endif
                        <div class="p-5">
                            <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $resource->name }}</h3>
                            @if ($resource->location)
                                <p class="text-xs text-zinc-400 mt-0.5">{{ $resource->location }}</p>
                            @endif
                            @if ($resource->description)
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300 line-clamp-2">{{ $resource->description }}</p>
                            @endif

                            <div class="mt-3 text-xs">
                                @if ($data['current'])
                                    <span class="inline-flex items-center gap-1.5 text-red-600 dark:text-red-400 font-medium">
                                        <span class="inline-block h-2 w-2 rounded-full bg-red-500"></span>
                                        Upptaget till {{ $data['current']->ends_at->format('H:i') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-green-600 dark:text-green-400 font-medium">
                                        <span class="inline-block h-2 w-2 rounded-full bg-green-500"></span>
                                        Ledigt nu
                                    </span>
                                @endif

                                @if ($data['upcoming']->isNotEmpty())
                                    <p class="mt-1.5 text-zinc-400">
                                        Nästa: {{ $data['upcoming']->first()->starts_at->format('H:i') }}
                                        – {{ $data['upcoming']->first()->title }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    @if ($uncategorized->isNotEmpty())
        <div class="space-y-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Övrigt</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($uncategorized as $resource)
                    @php $data = $bookingData[$resource->id] ?? ['current' => null, 'upcoming' => collect()]; @endphp
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-5">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $resource->name }}</h3>
                        @if ($resource->description)
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300 line-clamp-2">{{ $resource->description }}</p>
                        @endif
                        <div class="mt-2 text-xs">
                            @if ($data['current'])
                                <span class="text-red-600 dark:text-red-400">Upptaget till {{ $data['current']->ends_at->format('H:i') }}</span>
                            @else
                                <span class="text-green-600 dark:text-green-400">Ledigt</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</div>
</x-layouts.public>
