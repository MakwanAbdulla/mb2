<?php

use App\Models\AuditLog;
use App\Models\Content\MailingTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Utskicksmallar');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);
    }

    #[Computed]
    public function templates()
    {
        return MailingTemplate::withCount('mailings')
            ->with('creator')
            ->latest()
            ->get();
    }

    public function delete(int $id): void
    {
        $template = MailingTemplate::findOrFail($id);
        abort_unless($template->mailings()->doesntExist(), 422);

        $name = $template->name;
        $template->delete();

        AuditLog::record(action: 'mailing_template_deleted', area: 'mailings', subject: $name);

        unset($this->templates);
    }
}; ?>

<div>
    <div class="mb-4 flex items-center justify-end">
        <a href="{{ route('admin.mailing-templates.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-bootstrap-icon name="plus" class="size-4" /> Ny mall
        </a>
    </div>

    @if($this->templates->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 px-6 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="file-earmark-text" class="mx-auto size-8 text-zinc-400" />
            <p class="mt-3 font-medium text-zinc-700 dark:text-zinc-300">Inga mallar än</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Skapa en mall för att återanvända i utskick.</p>
            <a href="{{ route('admin.mailing-templates.create') }}" wire:navigate class="btn btn-secondary mt-4">
                <x-bootstrap-icon name="plus" class="size-4" /> Skapa mall
            </a>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Namn</th>
                        <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 sm:table-cell">Behörighet</th>
                        <th class="hidden px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 md:table-cell">Utskick</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($this->templates as $template)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-5 py-3">
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $template->name }}</p>
                                @if($template->description)
                                    <p class="text-xs text-zinc-400">{{ $template->description }}</p>
                                @endif
                            </td>
                            <td class="hidden px-5 py-3 sm:table-cell">
                                @if($template->permission_required)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        <x-bootstrap-icon name="lock" class="size-3" /> {{ $template->permission_required }}
                                    </span>
                                @else
                                    <span class="text-xs text-zinc-400">Alla med utskicksbehörighet</span>
                                @endif
                            </td>
                            <td class="hidden px-5 py-3 text-right text-zinc-500 dark:text-zinc-400 md:table-cell">
                                {{ $template->mailings_count }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.mailing-templates.edit', $template) }}" wire:navigate
                                       class="btn btn-ghost btn-sm">Redigera</a>
                                    @if($template->mailings_count === 0)
                                        <button wire:click="delete({{ $template->id }})"
                                                wire:confirm="Ta bort mallen '{{ $template->name }}'?"
                                                class="btn btn-ghost btn-sm text-red-500">Ta bort</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
