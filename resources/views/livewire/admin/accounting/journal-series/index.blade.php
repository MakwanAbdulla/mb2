<?php

use App\Models\Accounting\JournalSeries;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Verifikationsserier');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function series()
    {
        return JournalSeries::orderBy('sort_order')->get();
    }
};
?>

<div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-16">Serie</th>
                    <th class="px-4 py-3">Benämning</th>
                    <th class="px-4 py-3 w-28 text-center">Manuell</th>
                    <th class="px-4 py-3 w-28 text-center">Låsbar</th>
                    <th class="px-4 py-3 w-28 text-center">System</th>
                    <th class="px-4 py-3">Förvalt för</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->series as $s)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center justify-center size-8 rounded bg-zinc-100 dark:bg-zinc-700 font-mono font-bold text-sm">
                                {{ $s->code }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium">{{ $s->name }}</td>
                        <td class="px-4 py-3 text-center">
                            @if ($s->is_manual)
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-600 dark:text-green-400 mx-auto" />
                            @else
                                <x-bootstrap-icon name="dash" class="size-4 text-zinc-300 dark:text-zinc-600 mx-auto" />
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($s->is_lockable)
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-600 dark:text-green-400 mx-auto" />
                            @else
                                <x-bootstrap-icon name="dash" class="size-4 text-zinc-300 dark:text-zinc-600 mx-auto" />
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($s->is_system)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                                    <x-bootstrap-icon name="gear-fill" class="size-3" /> Auto
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $s->default_for ?? '–' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-xs text-zinc-400 dark:text-zinc-500">
        Verifikationsserierna konfigureras i Fortnox-export och synkroniseras via seeder.
        Kontakta systemadministratören för att lägga till eller ändra serier.
    </p>
</div>
