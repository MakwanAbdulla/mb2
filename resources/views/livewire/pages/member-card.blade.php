<?php

use App\Services\MemberCardService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.focused');
title('Mitt medlemskort');

new class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->check() && auth()->user()->member !== null, 403);
    }

    #[\Livewire\Attributes\Computed]
    public function member()
    {
        return auth()->user()->member->load('sections', 'membershipPeriods');
    }

    #[\Livewire\Attributes\Computed]
    public function currentPeriod()
    {
        return $this->member->membershipPeriods
            ->where('year', now()->year)
            ->where('status', \App\Models\Membership\MembershipPeriod::STATUS_ACTIVE)
            ->first();
    }

    #[\Livewire\Attributes\Computed]
    public function qrSvg(): string
    {
        $member = $this->member;
        $token  = MemberCardService::generateToken($member->id);
        $url    = route('member.verify', ['member' => $member->id, 'token' => $token]);
        $writer = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd()));
        return $writer->writeString($url);
    }
}; ?>

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6">

    {{-- Member card --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-dark via-zinc-900 to-complement p-6 shadow-2xl ring-1 ring-white/10">

        {{-- Shimmer overlay --}}
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-tr from-white/5 via-transparent to-transparent"></div>

        {{-- Close button --}}
        <a href="{{ route('dashboard') }}" wire:navigate
           class="absolute right-3 top-3 z-10 inline-flex size-8 items-center justify-center rounded-full bg-white/10 text-zinc-400 hover:bg-white/20 hover:text-white"
           aria-label="Stäng">
            <x-bootstrap-icon name="x-lg" class="size-4" />
        </a>

        {{-- Card header: Logo + association name --}}
        <div class="mb-5 flex items-center justify-between">
            <div>
                <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="Armagedon" class="h-8">
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-zinc-400">
                Armagedon
            </span>
        </div>

        {{-- Brand divider --}}
        <div class="mb-5 h-0.5 rounded-full bg-gradient-to-r from-brand via-brand-orange to-complement opacity-70"></div>

        {{-- Main content: Avatar + info --}}
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start">

            {{-- Left: Avatar + name --}}
            <div class="flex flex-col items-center gap-3 sm:items-start">
                <img
                    src="{{ auth()->user()->avatarUrl(120) }}"
                    alt="{{ $this->member->full_name }}"
                    class="size-20 rounded-full object-cover ring-2 ring-brand/40 sm:size-24"
                >
                <div class="text-center sm:text-left">
                    <div class="text-xl font-bold text-white">{{ $this->member->full_name }}</div>
                    <div class="mt-0.5 text-sm text-zinc-400">
                        Medlem sedan {{ $this->member->created_at->year }}
                    </div>
                    @if($this->member->membership_number)
                    <div class="mt-1 font-mono text-xs text-zinc-500">
                        #{{ $this->member->membership_number }}
                    </div>
                    @endif
                </div>
            </div>

            {{-- Divider on mobile --}}
            <div class="h-px bg-white/10 sm:hidden"></div>

            {{-- Right: Membership info --}}
            <div class="flex flex-1 flex-col gap-3">

                @if($this->currentPeriod)
                    {{-- Active membership --}}
                    <div>
                        <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Medlemskap</div>
                        <div class="text-base font-semibold text-white">
                            {{ match($this->currentPeriod->membership_type) {
                                'single'   => 'Enskild',
                                'family'   => 'Familj',
                                'youth'    => 'Ungdom',
                                'child'    => 'Barn',
                                'student'  => 'Student',
                                'senior'   => 'Senior',
                                'honorary' => 'Hedersmedlem',
                                default    => ucfirst($this->currentPeriod->membership_type),
                            } }}
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Status</div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-green-500/20 px-2.5 py-1 text-sm font-semibold text-green-400 ring-1 ring-green-500/30">
                            <x-bootstrap-icon name="check-circle-fill" class="size-3.5" />
                            Aktiv {{ $this->currentPeriod->year }}
                        </span>
                    </div>
                @else
                    {{-- No active period --}}
                    <div>
                        <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Status</div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-500/20 px-2.5 py-1 text-sm font-semibold text-red-400 ring-1 ring-red-500/30">
                            <x-bootstrap-icon name="x-circle-fill" class="size-3.5" />
                            Ej betald {{ now()->year }}
                        </span>
                    </div>
                @endif

                {{-- Sections --}}
                @if($this->member->sections->isNotEmpty())
                <div>
                    <div class="mb-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500">Grupper</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($this->member->sections as $section)
                        <span class="inline-flex items-center rounded-full bg-complement/30 px-2.5 py-0.5 text-xs font-medium text-violet-300 ring-1 ring-complement/40">
                            {{ $section->name }}
                        </span>
                        @endforeach
                    </div>
                </div>
                @endif

            </div>
        </div>

        {{-- QR code section --}}
        <div class="mt-6 border-t border-white/10 pt-5">
            <div class="flex flex-col items-center gap-3">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                    Skanna för att verifiera
                </div>

                {{-- QR code — regenerates every second --}}
                <div wire:poll.20000ms="$refresh" wire:loading.ignore class="rounded-xl bg-white p-3 shadow-lg">
                    {!! $this->qrSvg !!}
                </div>

                <div class="text-center text-xs text-zinc-600">
                    Koden uppdateras automatiskt
                </div>
            </div>
        </div>

    </div>

    {{-- Info below card --}}
    <div class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-start gap-3">
            <x-bootstrap-icon name="info-circle" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                QR-koden är unik för varje tillfälle och kan bara läsas av Armagedon. Du kan alltid
                komma åt ditt digitala kort här på <strong class="text-zinc-700 dark:text-zinc-300">/mitt-kort</strong>.
            </p>
        </div>
    </div>

</div>
