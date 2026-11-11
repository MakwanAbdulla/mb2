<?php

use App\Models\InboxMailbox;
use App\Services\InboxReaderService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Min inkorg');

new class extends Component {

    public InboxMailbox $mailbox;

    public function mount(): void
    {
        $reader = InboxReaderService::forOwner(Auth::user());
        abort_unless($reader?->isConfigured(), 404);

        $this->mailbox = $reader->record();
    }
}; ?>

<div>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">

        <div class="mb-6 flex items-center gap-3">
            <h1 class="text-lg font-semibold text-zinc-900 dark:text-white">Min inkorg</h1>
            <span class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $mailbox->address() }}</span>
        </div>

        <livewire:admin.shared.inbox-panel :mailbox-id="$mailbox->id" />

    </div>
</div>
