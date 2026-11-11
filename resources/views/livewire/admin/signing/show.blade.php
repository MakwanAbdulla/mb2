<?php

use App\Enums\SigningStatus;
use App\Models\AuditLog;
use App\Models\Signing\SigningRequest;
use App\Services\Signing\SigningService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Signeringsärende');

new class extends Component {
    public SigningRequest $signingRequest;

    public ?string $actionError = null;

    public function mount(SigningRequest $signingRequest): void
    {
        abort_unless($signingRequest->isVisibleTo(Auth::user()), 403);

        $this->signingRequest = $signingRequest;
    }

    public function sync(): void
    {
        $this->act('sync', 'Kunde inte hämta status från DocSign');
    }

    public function pause(): void
    {
        $this->act('pause', 'Kunde inte pausa', 'signing_request_paused');
    }

    public function resume(): void
    {
        $this->act('resume', 'Kunde inte återuppta', 'signing_request_resumed');
    }

    public function cancel(): void
    {
        $this->act('cancel', 'Kunde inte avbryta', 'signing_request_cancelled');
    }

    private function act(string $method, string $failure, ?string $auditAction = null): void
    {
        abort_unless($this->signingRequest->isManageableBy(Auth::user()), 403);
        $this->actionError = null;

        try {
            app(SigningService::class)->{$method}($this->signingRequest);
            if ($auditAction) {
                AuditLog::record($auditAction, 'signing', $this->signingRequest->name, $this->signingRequest);
            }
        } catch (Throwable $e) {
            $message = $e instanceof \App\Services\Signing\DocsignException ? $e->summary() : $e->getMessage();
            $this->actionError = "{$failure}: {$message}";
        }

        $this->signingRequest->refresh();
    }

    /** Only for signing.manage: whoever has the link can sign as that signer. */
    public function signUrl(int $requestSignerId): ?string
    {
        abort_unless(Auth::user()->can('signing.manage'), 403);

        $requestSigner = $this->signingRequest->signers()->findOrFail($requestSignerId);
        AuditLog::record('signing_sign_url_copied', 'signing', $this->signingRequest->name.' — '.$requestSigner->signer->name, $this->signingRequest);

        return $requestSigner->sign_url;
    }

    public function with(): array
    {
        $this->signingRequest->load(['files', 'signers.signer.user', 'events', 'creator', 'signable']);

        return [
            'request' => $this->signingRequest,
            'intact' => $this->signingRequest->signedPdfIsIntact(),
        ];
    }

    public function statusClasses(SigningStatus $status): string
    {
        return match ($status->color()) {
            'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
            'green' => 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
            'red' => 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
            default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300',
        };
    }
}; ?>

<x-slot:header>
    <x-page-header :title="$signingRequest->name" :subtitle="'Signeringsärende'.($signingRequest->reference ? ' · referens #'.$signingRequest->reference : '')" permission="signing.view" />
</x-slot:header>

<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">{{ session('error') }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.signing.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Alla signeringsärenden</a>
        @if ($request->isManageableBy(Auth::user()))
            <div class="flex flex-wrap items-center gap-2">
                @if ($request->provider_document_id && $request->status !== SigningStatus::Cancelled)
                    <flux:button wire:click="sync" size="sm" variant="ghost">
                        <x-bootstrap-icon name="arrow-clockwise" class="size-4" /> Synka nu
                    </flux:button>
                @endif
                @if ($request->status === SigningStatus::Pending)
                    <flux:button wire:click="pause" size="sm" variant="ghost">
                        <x-bootstrap-icon name="pause-circle" class="size-4" /> Pausa
                    </flux:button>
                @elseif ($request->status === SigningStatus::Paused)
                    <flux:button wire:click="resume" size="sm" variant="ghost">
                        <x-bootstrap-icon name="play-circle" class="size-4" /> Återuppta
                    </flux:button>
                @endif
                @unless (in_array($request->status, [SigningStatus::Completed, SigningStatus::Cancelled], true))
                    <flux:button wire:click="cancel" wire:confirm="Avbryta ärendet? Dokumentet raderas hos DocSign och kan inte längre signeras. Filerna och händelseloggen finns kvar här." size="sm" variant="danger">
                        <x-bootstrap-icon name="x-circle" class="size-4" /> Avbryt ärendet
                    </flux:button>
                @endunless
            </div>
        @endif
    </div>

    {{-- Summary --}}
    <div class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-3">
        <div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Status</p>
            <p class="mt-1">
                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $this->statusClasses($request->status) }}">{{ $request->status->label() }}</span>
                @if ($request->is_demo)
                    <span class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">demo</span>
                @endif
            </p>
        </div>
        <div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Skapad</p>
            <p class="mt-1 text-zinc-900 dark:text-white">{{ $request->created_at->format('d/m Y H:i') }}{{ $request->creator ? ' av '.$request->creator->name : '' }}</p>
        </div>
        <div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Skickad / klar</p>
            <p class="mt-1 text-zinc-900 dark:text-white">{{ $request->sent_at?->format('d/m Y H:i') ?? '–' }} / {{ $request->completed_at?->format('d/m Y H:i') ?? '–' }}</p>
        </div>
        @if ($request->comment)
            <div class="sm:col-span-3">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Meddelande</p>
                <p class="mt-1 whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $request->comment }}</p>
            </div>
        @endif
        @if ($request->last_error)
            <div class="sm:col-span-3 rounded-lg bg-red-50 px-3 py-2 text-red-700 dark:bg-red-500/10 dark:text-red-300">Senaste fel: {{ $request->last_error }}</div>
        @endif
        @if ($actionError)
            <div class="sm:col-span-3 rounded-lg bg-red-50 px-3 py-2 text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $actionError }}</div>
        @endif
    </div>

    {{-- Documents --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Dokument</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @foreach ($request->files as $file)
                <li><a href="{{ route('admin.signing.file', $file) }}" class="inline-flex items-center gap-2 text-zinc-700 hover:underline dark:text-zinc-300"><x-bootstrap-icon name="file-earmark-pdf" class="size-4" /> {{ $file->name }}</a></li>
            @endforeach
            @if ($request->signed_path)
                <li class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.signing.signed', $request) }}" class="inline-flex items-center gap-2 font-medium text-zinc-900 hover:underline dark:text-white"><x-bootstrap-icon name="file-earmark-check" class="size-4" /> Signerad PDF</a>
                    @if ($intact === true)
                        <span class="text-xs text-green-700 dark:text-green-400">Oförändrad sedan den hämtades från DocSign</span>
                    @elseif ($intact === false)
                        <span class="text-xs font-medium text-red-600 dark:text-red-400">Filen har ändrats sedan den hämtades från DocSign</span>
                    @endif
                </li>
            @elseif ($request->status === SigningStatus::Completed)
                <li class="text-xs text-amber-700 dark:text-amber-400">Den signerade PDF:en är inte hämtad än. Försök med "Synka nu".</li>
            @endif
        </ul>
    </div>

    {{-- Signers --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="px-5 pt-5 text-sm font-semibold text-zinc-900 dark:text-white">Undertecknare ({{ $request->signedCount() }} av {{ $request->signers->count() }} har signerat)</h2>
        <div class="overflow-x-auto">
            <table class="mt-3 w-full text-sm">
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($request->signers as $requestSigner)
                        <tr>
                            <td class="px-5 py-3">
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $requestSigner->signer->name }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ collect([$requestSigner->role_label, $requestSigner->signer->email, $requestSigner->signer->phone_number])->filter()->implode(' · ') }}
                                    · {{ $requestSigner->signer->sign_method->label() }}
                                    · {{ $requestSigner->signer->isExternal() ? 'extern' : 'användare' }}
                                </p>
                            </td>
                            <td class="px-5 py-3 text-right">
                                @if ($requestSigner->hasSigned())
                                    <span class="text-green-700 dark:text-green-400">Signerade {{ $requestSigner->signed_at->format('d/m Y H:i') }}</span>
                                    @if ($requestSigner->signed_by_name && $requestSigner->signed_by_name !== $requestSigner->signer->name)
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">som {{ $requestSigner->signed_by_name }} (enligt BankID)</p>
                                    @endif
                                @else
                                    <span class="text-zinc-500 dark:text-zinc-400">Väntar</span>
                                    @can('signing.manage')
                                        @if ($requestSigner->sign_url && $request->status->isOpen())
                                            <div x-data="{ copied: false }" class="mt-1">
                                                <button type="button" class="text-xs text-zinc-500 underline hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
                                                        x-on:click="$wire.signUrl({{ $requestSigner->id }}).then(url => { navigator.clipboard.writeText(url); copied = true; setTimeout(() => copied = false, 2000) })">
                                                    <span x-show="! copied">Kopiera signeringslänk</span>
                                                    <span x-show="copied" x-cloak>Kopierad</span>
                                                </button>
                                            </div>
                                        @endif
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Event log --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Händelser</h2>
        <ol class="mt-3 space-y-2 text-sm">
            @foreach ($request->events as $event)
                <li class="flex flex-wrap gap-x-3">
                    <span class="w-32 shrink-0 text-zinc-500 dark:text-zinc-400">{{ $event->created_at->format('d/m Y H:i') }}</span>
                    <span class="text-zinc-900 dark:text-white">{{ $event->type->label() }}</span>
                    @can('signing.manage')
                        @if ($event->payload)
                            <details class="w-full pl-32 text-xs text-zinc-500 dark:text-zinc-400">
                                <summary class="cursor-pointer">Detaljer</summary>
                                <pre class="mt-1 overflow-x-auto rounded bg-zinc-50 p-2 dark:bg-zinc-800">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                    @endcan
                </li>
            @endforeach
        </ol>
    </div>
</div>
