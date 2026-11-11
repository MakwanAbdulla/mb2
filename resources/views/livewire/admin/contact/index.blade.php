<?php

use App\Models\AuditLog;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kontaktmeddelanden');

new class extends Component {
    use WithPagination;

    public string $filterStatus = 'open';
    public string $search       = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['members.manage', 'admin']), 403);
    }

    #[Computed]
    public function messages()
    {
        return ContactMessage::query()
            ->when($this->filterStatus === 'open', fn ($q) => $q->open())
            ->when($this->filterStatus !== 'open' && $this->filterStatus !== 'all',
                fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) =>
                $q2->where('name', 'like', "%{$this->search}%")
                   ->orWhere('email', 'like', "%{$this->search}%")
                   ->orWhere('subject', 'like', "%{$this->search}%")
            ))
            ->orderByRaw("FIELD(status, 'new', 'read', 'replied', 'spam')")
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    #[Computed]
    public function newCount(): int
    {
        return ContactMessage::new()->count();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }

    public function markSpam(ContactMessage $message): void
    {
        $message->markSpam();
        AuditLog::record('contact_marked_spam', 'cms', $message->subject, null);
        unset($this->messages, $this->newCount);
    }

    public function delete(ContactMessage $message): void
    {
        $subject = $message->subject;
        $message->delete();
        AuditLog::record('contact_deleted', 'cms', $subject, null);
        unset($this->messages, $this->newCount);
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'new'     => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
            'read'    => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
            'replied' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
            'spam'    => 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500',
            default   => 'bg-zinc-100 text-zinc-500',
        };
    }
};
?>

<div>
    <x-slot:header>
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">Kontaktmeddelanden</h1>
                @if ($this->newCount > 0)
                    <p class="text-sm text-violet-600 dark:text-violet-400 mt-0.5 font-medium">
                        {{ $this->newCount }} nya meddelanden
                    </p>
                @endif
            </div>
        </div>
    </x-slot:header>

    <div class="mb-4 flex gap-3 flex-wrap">
        <flux:input wire:model.live="search" placeholder="Sök namn, e-post, ämne…" class="w-64" />
        <flux:select wire:model.live="filterStatus" class="w-44">
            <option value="open">Öppna (ny + läst)</option>
            <option value="new">Nya</option>
            <option value="read">Lästa</option>
            <option value="replied">Besvarade</option>
            <option value="spam">Skräppost</option>
            <option value="all">Alla</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Avsändare</th>
                    <th class="px-4 py-3">Ämne</th>
                    <th class="px-4 py-3 w-32">Datum</th>
                    <th class="px-4 py-3 w-28 text-center">Status</th>
                    <th class="px-4 py-3 w-24"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->messages as $msg)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $msg->status === 'new' ? 'font-medium' : '' }}">
                        <td class="px-4 py-3">
                            <div class="text-zinc-900 dark:text-white">{{ $msg->name }}</div>
                            <div class="text-xs text-zinc-400">{{ $msg->email }}</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300 max-w-xs truncate">
                            {{ $msg->subject }}
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-400">
                            {{ $msg->created_at->format('d/m Y H:i') }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $this->statusColor($msg->status) }}">
                                {{ $msg->statusLabel() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.contact.show', $msg) }}" wire:navigate
                               class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                Visa
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga meddelanden hittades.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->messages->hasPages())
        <div class="mt-4">{{ $this->messages->links() }}</div>
    @endif
</div>
