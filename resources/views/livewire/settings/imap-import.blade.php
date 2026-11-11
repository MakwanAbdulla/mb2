<?php

use App\Models\ImapImportJob;
use App\Services\ImapImportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $host       = '';
    public string $port       = '993';
    public string $encryption = 'ssl';
    public string $username   = '';
    public string $password   = '';

    public ?ImapImportJob $job = null;

    // After successful test
    public bool   $verified      = false;
    public int    $verifiedCount = 0;
    public string $testError     = '';

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless((bool) Auth::user()->member?->workspaceAccount?->email, 403);

        $this->job = ImapImportJob::where('user_id', Auth::id())
            ->whereIn('status', [ImapImportJob::STATUS_PENDING, ImapImportJob::STATUS_RUNNING, ImapImportJob::STATUS_COMPLETED, ImapImportJob::STATUS_FAILED])
            ->latest()
            ->first();
    }

    public function getListeners(): array
    {
        return $this->job?->isRunning() ? ['$refresh' => '$refresh'] : [];
    }

    public function test(ImapImportService $service): void
    {
        $this->validate([
            'host'       => 'required|string',
            'port'       => 'required|integer|min:1|max:65535',
            'encryption' => 'required|in:ssl,tls,none',
            'username'   => 'required|string',
            'password'   => 'required|string',
        ]);

        $this->testError  = '';
        $this->verified   = false;

        try {
            $this->verifiedCount = $service->testConnection(
                $this->host,
                (int) $this->port,
                $this->encryption,
                $this->username,
                $this->password,
            );
            $this->verified = true;
        } catch (\RuntimeException $e) {
            $this->testError = $e->getMessage();
        }
    }

    public function queue(): void
    {
        abort_unless($this->verified, 403);

        // Cancel any previous failed job so user can retry
        ImapImportJob::where('user_id', Auth::id())
            ->whereIn('status', [ImapImportJob::STATUS_FAILED, ImapImportJob::STATUS_COMPLETED])
            ->delete();

        $this->job = ImapImportJob::create([
            'user_id'    => Auth::id(),
            'host'       => $this->host,
            'port'       => (int) $this->port,
            'encryption' => $this->encryption,
            'username'   => $this->username,
            'password'   => $this->password,
            'status'     => ImapImportJob::STATUS_PENDING,
        ]);

        $this->reset('host', 'port', 'username', 'password', 'verified', 'verifiedCount', 'testError');
        $this->port = '993';
        $this->encryption = 'ssl';
    }

    public function cancel(): void
    {
        if ($this->job && ($this->job->isPending() || $this->job->isFailed())) {
            $this->job->delete();
            $this->job = null;
        }
    }

    public function polling(): bool
    {
        return $this->job?->isRunning() || $this->job?->isPending();
    }
}; ?>

<div @if($this->polling()) wire:poll.5000ms @endif>
    @php $wsEmail = auth()->user()->member?->workspaceAccount?->email; @endphp
    @if($wsEmail)
        <div class="mb-4 flex items-center justify-between">
            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                Mail importeras till <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ $wsEmail }}</span>
            </p>
            <x-gmail-link :email="$wsEmail" />
        </div>
    @endif
    @if($job)
        {{-- Status card --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="font-medium text-zinc-900 dark:text-white">
                        @if($job->isPending())
                            <span class="inline-flex items-center gap-1.5">
                                <x-bootstrap-icon name="clock" class="size-4 text-amber-500" />
                                Väntar på att köras...
                            </span>
                        @elseif($job->isRunning())
                            <span class="inline-flex items-center gap-1.5">
                                <x-bootstrap-icon name="arrow-repeat" class="size-4 animate-spin text-blue-500" />
                                Importerar...
                            </span>
                        @elseif($job->isCompleted())
                            <span class="inline-flex items-center gap-1.5">
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500" />
                                Import klar
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5">
                                <x-bootstrap-icon name="x-circle-fill" class="size-4 text-red-500" />
                                Import misslyckades
                            </span>
                        @endif
                    </p>

                    @if($job->isRunning() || $job->isCompleted())
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ number_format($job->messages_imported) }} av {{ $job->messages_total ? number_format($job->messages_total) : '?' }} meddelanden importerade
                        </p>
                        @if($job->current_folder)
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">Mapp: {{ $job->current_folder }}</p>
                        @endif
                    @endif

                    @if($job->isRunning() && $job->messages_total)
                        @php $pct = min(100, (int) round($job->messages_imported / $job->messages_total * 100)) @endphp
                        <div class="mt-2 h-2 w-64 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                            <div class="h-2 rounded-full bg-brand transition-all" style="width: {{ $pct }}%"></div>
                        </div>
                    @endif

                    @if($job->isFailed() && $job->error)
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $job->error }}</p>
                    @endif

                    @if($job->isCompleted() && $job->completed_at)
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Färdig {{ $job->completed_at->format('d/m Y H:i') }}</p>
                    @endif
                </div>

                @if($job->isPending() || $job->isFailed())
                    <button wire:click="cancel" wire:confirm="Avbryt importen?"
                            class="btn btn-ghost text-sm">
                        Avbryt
                    </button>
                @endif
            </div>
        </div>
    @else
        {{-- Credential form --}}
        <div class="space-y-4">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Ange inloggningsuppgifterna för ditt gamla e-postkonto. Dina uppgifter testas direkt och sparas sedan krypterat — importen startar automatiskt inom 5 minuter.
            </p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="lbl">IMAP-server (host)</label>
                    <input wire:model="host" type="text" placeholder="mail.loopia.se" class="inp" />
                    @error('host') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="lbl">Port</label>
                    <input wire:model="port" type="number" placeholder="993" class="inp" />
                    @error('port') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="lbl">Kryptering</label>
                    <select wire:model="encryption" class="inp">
                        <option value="ssl">SSL (port 993)</option>
                        <option value="tls">STARTTLS (port 143)</option>
                        <option value="none">Ingen</option>
                    </select>
                </div>

                <div>
                    <label class="lbl">Användarnamn / e-post</label>
                    <input wire:model="username" type="text" autocomplete="off" class="inp" />
                    @error('username') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="lbl">Lösenord</label>
                    <input wire:model="password" type="password" autocomplete="off" class="inp" />
                    @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            @if($testError)
                <div class="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                    <x-bootstrap-icon name="exclamation-circle" class="size-4 shrink-0" />
                    {{ $testError }}
                </div>
            @endif

            @if($verified)
                <div class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
                    <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0" />
                    Anslutning verifierad — {{ number_format($verifiedCount) }} meddelanden i inkorgen hittades.
                </div>
            @endif

            <div class="flex gap-3">
                @if(!$verified)
                    <button wire:click="test" wire:loading.attr="disabled"
                            class="btn btn-secondary">
                        <wire:loading wire:target="test">
                            <x-bootstrap-icon name="arrow-repeat" class="size-4 animate-spin" />
                        </wire:loading>
                        Testa anslutning
                    </button>
                @else
                    <button wire:click="queue"
                            class="btn btn-primary">
                        Starta import
                    </button>
                    <button wire:click="$set('verified', false)"
                            class="btn btn-ghost text-sm">
                        Ändra uppgifter
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
