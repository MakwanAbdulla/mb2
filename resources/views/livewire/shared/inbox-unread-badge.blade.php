<?php

use App\Models\InboxMailbox;
use App\Services\InboxReaderService;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    public string $purpose = '';
    public int    $ownerId = 0;

    #[Computed]
    public function count(): int
    {
        try {
            if ($this->purpose) {
                $reader = InboxReaderService::forPurpose($this->purpose);
            } elseif ($this->ownerId) {
                $mb = InboxMailbox::find($this->ownerId);
                $reader = $mb ? new InboxReaderService($mb) : null;
            } else {
                return 0;
            }

            return $reader?->unreadCount() ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}; ?>

<span @class([
    'ml-auto rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-tight',
    'bg-complement text-white dark:bg-complement-light' => $this->count > 0,
    'hidden'                                            => $this->count === 0,
])>{{ $this->count > 99 ? '99+' : $this->count }}</span>
