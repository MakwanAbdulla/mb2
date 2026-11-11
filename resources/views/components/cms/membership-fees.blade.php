@php
    use App\Models\Membership\MembershipFee;

    $typeLabels = [
        'single'    => 'Vuxen',
        'youth'     => 'Ungdom',
        'child'     => 'Barn',
        'student'   => 'Student',
        'senior'    => 'Senior',
        'family'    => 'Familj',
        'honorary'  => 'Hedersmedlem',
    ];

    $currentYear = now()->year;
    $nextYear    = $currentYear + 1;

    $feesByYear = MembershipFee::whereIn('year', [$currentYear, $nextYear])
        ->where('is_active', true)
        ->orderBy('year')
        ->orderBy('sort_order')
        ->get()
        ->groupBy('year');

    $yearsToShow = $feesByYear->keys()->sort()->values();
@endphp

@forelse($yearsToShow as $year)
    @php $fees = $feesByYear[$year]; @endphp
    <div class="not-prose my-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50 flex items-center justify-between gap-4">
            <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Medlemsavgifter {{ $year }}</p>
            @if($year == $nextYear)
                <span class="rounded-full bg-complement/10 px-2.5 py-0.5 text-xs font-medium text-complement dark:bg-complement/20 dark:text-complement-light">
                    Beslutade, gäller från 1 jan {{ $nextYear }}
                </span>
            @endif
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-100 dark:border-zinc-700">
                    <th class="px-4 py-2.5 text-left font-medium text-zinc-500 dark:text-zinc-400">Kategori</th>
                    <th class="px-4 py-2.5 text-left font-medium text-zinc-500 dark:text-zinc-400">Ålder</th>
                    <th class="px-4 py-2.5 text-right font-medium text-zinc-500 dark:text-zinc-400">Avgift</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach($fees as $fee)
                    <tr class="bg-white dark:bg-zinc-900">
                        <td class="px-4 py-2.5 font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $typeLabels[$fee->membership_type] ?? ucfirst($fee->membership_type) }}
                        </td>
                        <td class="px-4 py-2.5 text-zinc-500 dark:text-zinc-400">
                            {{ $fee->ageLabel() ?? '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $fee->formattedAmount() }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="border-t border-zinc-100 bg-zinc-50 px-4 py-2.5 text-xs text-zinc-400 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-500">
            Avgifter gäller 1 januari–31 december {{ $year }}.
        </div>
    </div>
@empty
    <p class="not-prose text-zinc-500 dark:text-zinc-400">Inga avgifter konfigurerade för {{ $currentYear }}.</p>
@endforelse
