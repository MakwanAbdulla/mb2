<?php

use App\Models\Content\MailLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    #[Computed]
    public function logs()
    {
        $memberId = Auth::user()->member?->id;
        if (! $memberId) {
            return collect();
        }

        return MailLog::where('member_id', $memberId)
            ->where('delivery_status', MailLog::STATUS_SENT)
            ->orderByDesc('sent_at')
            ->get(['id', 'subject', 'mail_type', 'sent_at', 'delivery_status']);
    }
}; ?>

<div class="mx-auto max-w-4xl py-8 px-4 sm:px-0">
    <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Mottagna meddelanden</h2>

    @if($this->logs->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 px-6 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="envelope" class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga meddelanden skickade till dig ännu.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Ämne</th>
                        <th class="hidden px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 sm:table-cell">Skickat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($this->logs as $log)
                        <tr>
                            <td class="px-5 py-3 text-zinc-900 dark:text-white">{{ $log->subject }}</td>
                            <td class="hidden px-5 py-3 text-zinc-500 dark:text-zinc-400 sm:table-cell">
                                {{ $log->sent_at->format('d/m Y H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
