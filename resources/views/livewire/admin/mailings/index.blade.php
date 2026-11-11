<?php

use App\Models\AuditLog;
use App\Models\Content\Mailing;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Utskick');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('mailings.manage'), 403);
    }

    #[Computed]
    public function mailings()
    {
        return Mailing::with('creator')
            ->latest()
            ->get();
    }

    public function delete(int $id): void
    {
        $mailing = Mailing::findOrFail($id);
        abort_unless($mailing->isDraft(), 422);

        $title = $mailing->title;
        $mailing->delete();

        AuditLog::record(action: 'mailing_deleted', area: 'mailings', subject: $title);
        unset($this->mailings);
    }
}; ?>

<div>
    <div class="mb-4 flex items-center justify-end">
        <a href="{{ route('admin.mailings.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-bootstrap-icon name="plus" class="size-4" /> Nytt utskick
        </a>
    </div>

    @if($this->mailings->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 px-6 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="envelope-paper" class="mx-auto size-8 text-zinc-400" />
            <p class="mt-3 font-medium text-zinc-700 dark:text-zinc-300">Inga utskick än</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Skapa ett utskick för att skicka e-post till dina medlemmar.</p>
            <a href="{{ route('admin.mailings.create') }}" wire:navigate class="btn btn-secondary mt-4">
                <x-bootstrap-icon name="plus" class="size-4" /> Skapa utskick
            </a>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Titel</th>
                        <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 sm:table-cell">Status</th>
                        <th class="hidden px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 md:table-cell">Mottagare</th>
                        <th class="hidden px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 lg:table-cell">Skickat</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($this->mailings as $mailing)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-5 py-3">
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $mailing->title }}</p>
                                <p class="text-xs text-zinc-400">{{ $mailing->subject }}</p>
                            </td>
                            <td class="hidden px-5 py-3 sm:table-cell">
                                @php
                                    $statusLabel = match($mailing->status) {
                                        'draft'     => 'Utkast',
                                        'scheduled' => 'Schemalagd',
                                        'sending'   => 'Skickas',
                                        'sent'      => 'Skickat',
                                        'cancelled' => 'Avbrutet',
                                        default     => $mailing->status,
                                    };
                                    $statusClass = match($mailing->status) {
                                        'draft'     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
                                        'scheduled' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                        'sending'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                        'sent'      => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                        'cancelled' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                        default     => 'bg-zinc-100 text-zinc-600',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td class="hidden px-5 py-3 text-right text-zinc-500 dark:text-zinc-400 md:table-cell">
                                {{ $mailing->recipients_count ?: $mailing->recipients()->count() }}
                            </td>
                            <td class="hidden px-5 py-3 text-right text-zinc-500 dark:text-zinc-400 lg:table-cell">
                                {{ $mailing->sent_at?->format('d/m Y H:i') ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if($mailing->isEditable())
                                        <a href="{{ route('admin.mailings.edit', $mailing) }}" wire:navigate
                                           class="btn btn-ghost btn-sm">Redigera</a>
                                        <button wire:click="delete({{ $mailing->id }})"
                                                wire:confirm="Ta bort utskicket '{{ $mailing->title }}'?"
                                                class="btn btn-ghost btn-sm text-red-500">Ta bort</button>
                                    @else
                                        <a href="{{ route('admin.mailings.edit', $mailing) }}" wire:navigate
                                           class="btn btn-ghost btn-sm">Visa</a>
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
