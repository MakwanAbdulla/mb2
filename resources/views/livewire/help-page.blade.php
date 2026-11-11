<?php

use App\Models\Content\HelpSection;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Hjälp & dokumentation');

new class extends Component {
    public function with(): array
    {
        $user    = Auth::user();
        $isAuth  = Auth::check();
        $canEdit = $user?->hasPermissionTo('help.manage') ?? false;
        $userPerms = $isAuth ? $user->getAllPermissions()->pluck('name')->all() : [];

        // Count visible sections per group for the cards
        $counts = HelpSection::query()
            ->where(function ($q) use ($isAuth, $userPerms, $canEdit) {
                $q->where('show_public', true);
                if ($isAuth) {
                    $q->orWhere('show_auth', true);
                }
                if ($userPerms) {
                    $q->orWhereIn('required_permission', $userPerms);
                }
                if ($canEdit) {
                    $q->orWhereNotNull('required_permission');
                }
            })
            ->selectRaw('group_label, count(*) as cnt')
            ->groupBy('group_label')
            ->pluck('cnt', 'group_label')
            ->all();

        $allGroups = HelpSection::groups();

        // Filter to groups that have at least one visible section (or user has the permission)
        $groups = $allGroups->filter(function ($group) use ($counts, $user, $isAuth, $canEdit) {
            $groupLabel = $group['label'];
            if (! isset($counts[$groupLabel])) {
                return false;
            }
            if ($group['permission'] && ! $canEdit) {
                if (! $isAuth || ! $user->hasPermissionTo($group['permission'])) {
                    return false;
                }
            }
            return true;
        })->values();

        return compact('groups', 'counts', 'canEdit');
    }
};
?>

<div class="py-8">
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">

    <div class="mb-8">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Hjälp &amp; dokumentation</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Välj ett ämnesområde nedan. Varje sida har ett eget sökfält.</p>
    </div>

    {{-- Gruppkort --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($groups as $group)
            @php
                $isAdmin = str_starts_with($group['slug'], 'admin-');
                $count   = $counts[$group['label']] ?? 0;
            @endphp
            <a
                href="{{ route('help.group', $group['slug']) }}"
                wire:navigate
                class="group flex flex-col rounded-xl border bg-white p-5 shadow-sm transition-all hover:shadow-md dark:bg-zinc-800
                       {{ $isAdmin
                            ? 'border-violet-200 hover:border-violet-400 dark:border-violet-800/40 dark:hover:border-violet-600'
                            : 'border-zinc-200 hover:border-brand dark:border-zinc-700 dark:hover:border-brand' }}"
            >
                <div class="mb-3 flex items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg
                                {{ $isAdmin ? 'bg-violet-100 dark:bg-violet-900/30' : 'bg-brand/20 dark:bg-brand/10' }}">
                        <x-bootstrap-icon
                            name="{{ $group['icon'] }}"
                            class="size-5 {{ $isAdmin ? 'text-violet-600 dark:text-violet-400' : 'text-brand-dark dark:text-brand' }}"
                        />
                    </div>
                    <h2 class="font-semibold text-zinc-900 group-hover:text-brand-dark dark:text-zinc-100 dark:group-hover:text-brand">
                        {{ $group['title'] }}
                    </h2>
                </div>
                <p class="mb-4 flex-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
                    {{ $group['description'] }}
                </p>
                <div class="flex items-center justify-between text-xs text-zinc-400 dark:text-zinc-500">
                    <span>{{ $count }} {{ $count === 1 ? 'avsnitt' : 'avsnitt' }}</span>
                    <x-bootstrap-icon name="arrow-right" class="size-3.5 transition-transform group-hover:translate-x-1" />
                </div>
            </a>
        @endforeach
    </div>

    @if ($canEdit)
        <div class="mt-8 text-right">
            <a href="{{ route('admin.help-sections.index') }}" wire:navigate
               class="text-xs text-zinc-400 transition-colors hover:text-brand dark:text-zinc-500 dark:hover:text-brand">
                Redigera hjälpsektioner →
            </a>
        </div>
    @endif

</div>
</div>
