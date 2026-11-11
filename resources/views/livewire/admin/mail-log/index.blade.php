<?php

use App\Models\Content\MailLog;
use App\Services\MailTemplateService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Mailhistorik');

new class extends Component {
    use WithPagination;

    public string  $search       = '';
    public string  $filterType   = '';
    public string  $filterDate   = '';    // 'today', 'week', 'month', or ''
    public string  $filterStatus = '';    // 'sent', 'failed', 'opened', or ''
    public ?int    $previewId    = null;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);
    }

    public function updatedSearch(): void       { $this->resetPage(); }
    public function updatedFilterType(): void   { $this->resetPage(); }
    public function updatedFilterDate(): void   { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }

    #[Computed]
    public function logs()
    {
        return MailLog::with(['member', 'user'])
            ->when($this->search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('recipient_email', 'like', '%' . $this->search . '%')
                       ->orWhere('recipient_name',  'like', '%' . $this->search . '%')
                       ->orWhere('subject',         'like', '%' . $this->search . '%')
                )
            )
            ->when($this->filterType, fn ($q) => $q->where('mail_type', $this->filterType))
            ->when($this->filterStatus === 'sent',   fn ($q) => $q->where('delivery_status', 'sent'))
            ->when($this->filterStatus === 'failed', fn ($q) => $q->where('delivery_status', 'failed'))
            ->when($this->filterStatus === 'opened', fn ($q) => $q->where('open_count', '>', 0))
            ->when($this->filterDate === 'today',  fn ($q) => $q->whereDate('sent_at', today()))
            ->when($this->filterDate === 'week',   fn ($q) => $q->where('sent_at', '>=', now()->subWeek()))
            ->when($this->filterDate === 'month',  fn ($q) => $q->where('sent_at', '>=', now()->subMonth()))
            ->latest('sent_at')
            ->paginate(30);
    }

    #[Computed]
    public function mailTypes(): array
    {
        return MailLog::whereNotNull('mail_type')
            ->distinct()
            ->orderBy('mail_type')
            ->pluck('mail_type')
            ->toArray();
    }

    #[Computed]
    public function typeLabels(): array
    {
        return array_map(
            fn ($t) => $t['label'],
            MailTemplateService::TEMPLATES,
        );
    }

    public function togglePreview(int $id): void
    {
        $this->previewId = $this->previewId === $id ? null : $id;
    }
};
?>

<x-slot:header>
    <x-page-header permission="settings.manage" title="Mailhistorik" />
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap gap-3">
        <div class="flex-1 min-w-48">
            <input wire:model.live.debounce.300ms="search"
                   type="search"
                   placeholder="Sök e-post, namn, ämne…"
                   class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
        </div>
        <select wire:model.live="filterStatus"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
            <option value="">Alla statusar</option>
            <option value="sent">Skickat</option>
            <option value="failed">Misslyckades</option>
            <option value="opened">Öppnat</option>
        </select>
        <select wire:model.live="filterType"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
            <option value="">Alla typer</option>
            @foreach($this->mailTypes as $type)
                <option value="{{ $type }}">{{ $this->typeLabels[$type] ?? $type }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterDate"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
            <option value="">Alla datum</option>
            <option value="today">Idag</option>
            <option value="week">Senaste 7 dagarna</option>
            <option value="month">Senaste 30 dagarna</option>
        </select>
    </div>

    {{-- Table --}}
    @if($this->logs->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <x-bootstrap-icon name="envelope-x" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga skickade mail matchar sökningen.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead>
                    <tr class="bg-zinc-50 dark:bg-zinc-800/80">
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Datum</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Mottagare</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Ämne / Typ</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @foreach($this->logs as $log)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/30 {{ $log->isFailed() ? 'bg-red-50/40 dark:bg-red-900/10' : '' }}">
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $log->sent_at->format('d/m Y H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ $log->recipient_name ?: $log->recipient_email }}
                                </div>
                                @if($log->recipient_name)
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $log->recipient_email }}</div>
                                @endif
                                @if($log->member)
                                    <a href="{{ route('admin.members.show', $log->member) }}" wire:navigate
                                       class="mt-0.5 inline-flex items-center gap-1 text-xs text-complement-light hover:underline dark:text-complement-light">
                                        <x-bootstrap-icon name="person" class="size-3" />
                                        {{ $log->member->full_name }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-zinc-800 dark:text-zinc-200">{{ $log->subject }}</div>
                                @if($log->mail_type)
                                    <span class="mt-0.5 inline-block rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                                        {{ $this->typeLabels[$log->mail_type] ?? $log->mail_type }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($log->isFailed())
                                    {{-- Delivery failed --}}
                                    <div x-data="{ showError: false }">
                                        <button type="button" @click="showError = !showError"
                                                class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400">
                                            <x-bootstrap-icon name="x-circle" class="size-3" />
                                            Misslyckades
                                        </button>
                                        <div x-show="showError" x-cloak
                                             class="mt-1 max-w-xs rounded bg-red-50 p-2 font-mono text-xs text-red-700 dark:bg-red-900/20 dark:text-red-400">
                                            {{ $log->delivery_error }}
                                        </div>
                                    </div>
                                @else
                                    {{-- Delivery OK — show open status --}}
                                    <span class="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400">
                                        <x-bootstrap-icon name="check-circle" class="size-3" />
                                        Skickat
                                    </span>
                                    @if($log->tracking_token)
                                        <div class="mt-1">
                                            @if($log->wasOpened())
                                                <span class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400"
                                                      title="Senast öppnat: {{ $log->first_opened_at?->format('d/m Y H:i') }}">
                                                    <x-bootstrap-icon name="eye" class="size-3" />
                                                    Öppnat {{ $log->open_count }}×
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 text-xs text-zinc-400 dark:text-zinc-500">
                                                    <x-bootstrap-icon name="eye-slash" class="size-3" />
                                                    Ej öppnat
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($log->body_html)
                                    <button type="button"
                                            wire:click="togglePreview({{ $log->id }})"
                                            class="rounded px-2 py-1 text-xs text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-700 dark:hover:text-zinc-300">
                                        {{ $previewId === $log->id ? 'Stäng' : 'Visa' }}
                                    </button>
                                @endif
                            </td>
                        </tr>

                        {{-- Inline body preview --}}
                        @if($previewId === $log->id && $log->body_html)
                            <tr>
                                <td colspan="4" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-900/40">
                                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                                        <div class="flex items-center justify-between border-b border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Förhandsvisning av mailinnehåll</span>
                                            <span class="text-xs text-zinc-400">{{ $log->sent_at->format('d/m Y H:i') }} → {{ $log->recipient_email }}</span>
                                        </div>
                                        <div class="max-h-[500px] overflow-y-auto bg-white dark:bg-zinc-900">
                                            <iframe srcdoc="{{ htmlspecialchars($log->body_html) }}"
                                                    class="h-[500px] w-full border-none"
                                                    sandbox="allow-same-origin"
                                                    title="Mailinnehåll"></iframe>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->logs->links() }}
        </div>
    @endif
</div>
