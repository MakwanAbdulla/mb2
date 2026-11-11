@php($id = 'swoosh-'.uniqid())

<span class="relative whitespace-nowrap">{{ $slot }}<svg
    viewBox="0 0 200 16"
    preserveAspectRatio="none"
    aria-hidden="true"
    class="pointer-events-none
    absolute -bottom-[0.2em] -left-[2%] h-[0.3em] w-[104%]
    overflow-visible"><defs>
        <linearGradient id="{{ $id }}" x1="0" x2="1">
            <stop offset="0" stop-color="#fbc712"/>
            <stop offset="1" stop-color="#eb8111"/>
        </linearGradient></defs>
        <path d="M3 11 C 45 3, 110 2, 197 7"
        fill="none" stroke="url(#{{ $id }})"
        stroke-width="5" stroke-linecap="round"/>
    </svg></span>
