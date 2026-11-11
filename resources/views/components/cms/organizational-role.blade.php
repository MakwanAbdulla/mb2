@php
    use App\Models\Organization\OrganizationalRole;
    use App\Models\Organization\OrganizationalRolePeriod;

    $slug = $params['slug'] ?? null;
    $year = isset($params['year']) ? (int) $params['year'] : now()->year;

    if (! $slug) {
        return;
    }

    $role = OrganizationalRole::where('slug', $slug)->first();

    if (! $role) {
        return;
    }

    // Prefer the active period; also accept forming/reporting so the page
    // stays up-to-date around transitions.
    $period = $role->periods()
        ->whereIn('status', [
            OrganizationalRolePeriod::STATUS_FORMING,
            OrganizationalRolePeriod::STATUS_ACTIVE,
            OrganizationalRolePeriod::STATUS_REPORTING,
        ])
        ->where(function ($q) use ($year) {
            $q->where(function ($q) use ($year) {
                // Calendar-year periods matching the requested year
                $q->where('period_type', OrganizationalRolePeriod::TYPE_CALENDAR_YEAR)
                  ->where('year', $year);
            })->orWhere(function ($q) {
                // Custom periods that are currently running
                $q->where('period_type', OrganizationalRolePeriod::TYPE_CUSTOM)
                  ->where('active_start', '<=', now())
                  ->where(fn ($q) => $q->whereNull('active_end')->orWhere('active_end', '>=', now()));
            });
        })
        ->latest('active_start')
        ->first();

    $members = $period
        ? $period->members()
            ->with('member')
            ->where('transition_role', '!=', \App\Models\Organization\OrganizationalRoleMember::TRANSITION_OUTGOING)
            ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>=', now()))
            ->orderByRaw("FIELD(transition_role, 'incoming', 'current') DESC")
            ->orderBy('id')
            ->get()
        : collect();
@endphp

<div class="not-prose my-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
    <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50 flex items-center justify-between gap-4">
        <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $role->name }}</p>
        @if($period)
            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $period->name }}</span>
        @endif
    </div>

    @if($members->isNotEmpty())
        <table class="w-full text-sm">
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach($members as $roleMember)
                    <tr class="bg-white dark:bg-zinc-900">
                        <td class="px-4 py-2.5 font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $roleMember->member->full_name ?? $roleMember->member->first_name . ' ' . $roleMember->member->last_name }}
                        </td>
                        <td class="px-4 py-2.5 text-zinc-500 dark:text-zinc-400">
                            {{ $roleMember->title }}
                            @if($roleMember->transition_role === \App\Models\Organization\OrganizationalRoleMember::TRANSITION_INCOMING)
                                <span class="ml-1 rounded-full bg-complement/10 px-1.5 py-0.5 text-xs font-medium text-complement dark:bg-complement/20 dark:text-complement-light">
                                    Tillträdande
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="px-4 py-4 text-sm text-zinc-500 dark:text-zinc-400">
            Inga ledamöter registrerade för {{ $role->name }}{{ $period ? ' ' . $period->name : '' }}.
        </p>
    @endif
</div>
