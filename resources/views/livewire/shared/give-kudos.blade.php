<?php

use App\Models\Membership\Member;
use App\Services\GamificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public int $recipientId;
    public string $note = '';
    public bool $sent   = false;
    public ?string $error = null;

    public function mount(int $recipientId): void
    {
        $this->recipientId = $recipientId;
    }

    #[\Livewire\Attributes\Computed]
    public function canGive(): bool
    {
        $giver = Auth::user()?->member;
        if (! $giver) {
            return false;
        }
        $recipient = Member::find($this->recipientId);
        if (! $recipient) {
            return false;
        }
        return app(GamificationService::class)->canGiveKudos($giver, $recipient);
    }

    #[\Livewire\Attributes\Computed]
    public function givenThisMonth(): int
    {
        $giver = Auth::user()?->member;
        return $giver ? app(GamificationService::class)->kudosGivenThisMonth($giver) : 0;
    }

    public function give(): void
    {
        $this->error = null;
        $giver = Auth::user()?->member;
        if (! $giver) {
            return;
        }

        $recipient = Member::findOrFail($this->recipientId);

        try {
            app(GamificationService::class)->giveKudos($giver, $recipient, $this->note ?: null);
            $this->sent = true;
            $this->note = '';
        } catch (\RuntimeException $e) {
            $this->error = 'Du har nått din kudos-gräns för månaden.';
        }
    }
}; ?>

<div>
    @if($sent)
        <span class="inline-flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400">
            <x-bootstrap-icon name="heart-fill" class="size-4" />
            Kudos skickat! +{{ \App\Services\GamificationService::KUDOS_XP }} XP
        </span>
    @elseif($this->canGive)
        <div class="flex items-start gap-2">
            <input wire:model="note" type="text" maxlength="100" placeholder="Valfri kommentar…"
                   class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            <button wire:click="give"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-pink-50 px-3 py-1.5 text-sm font-medium text-pink-600 hover:bg-pink-100 dark:bg-pink-900/20 dark:text-pink-400 dark:hover:bg-pink-900/40 border border-pink-200 dark:border-pink-800">
                <x-bootstrap-icon name="heart" class="size-4" />
                Ge kudos
            </button>
        </div>
        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            {{ \App\Services\GamificationService::KUDOS_LIMIT_PER_MONTH - $this->givenThisMonth }} kudos kvar denna månad
        </p>
        @if($error)
            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $error }}</p>
        @endif
    @else
        <span class="text-xs text-zinc-400 dark:text-zinc-500">
            @if(Auth::user()?->member?->id === $recipientId)
                Du kan inte ge kudos till dig själv.
            @else
                Du har gett {{ \App\Services\GamificationService::KUDOS_LIMIT_PER_MONTH }} kudos denna månad.
            @endif
        </span>
    @endif
</div>
