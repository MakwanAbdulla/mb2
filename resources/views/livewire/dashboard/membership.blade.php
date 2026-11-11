<?php

use App\Models\Membership\Member;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    #[Computed]
    public function member(): ?Member
    {
        return Member::where('user_id', auth()->id())
            ->with([
                'membershipPeriods' => fn ($q) => $q->orderByDesc('year'),
                'sections'          => fn ($q) => $q->wherePivotNull('left_at'),
                'sectionRoles'      => fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))->with('section'),
            ])
            ->first();
    }

    public function statusLabel(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'Väntar på betalning',
            Member::STATUS_ACTIVE   => 'Aktiv',
            Member::STATUS_INACTIVE => 'Inaktiv',
            Member::STATUS_EXPIRED  => 'Utgången',
            Member::STATUS_DECEASED => 'Avliden',
            default                 => $status,
        };
    }

    public function statusClasses(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            Member::STATUS_ACTIVE   => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            Member::STATUS_INACTIVE,
            Member::STATUS_EXPIRED  => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            default                 => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
        };
    }

    public function typeLabel(string $type): string
    {
        return match($type) {
            Member::TYPE_SINGLE   => 'Vuxen',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_HONORARY => 'Hedersmedlem',
            default               => $type,
        };
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
    @if(! $this->member)
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="person-plus" class="mx-auto mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Du är inte registrerad som medlem ännu</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                Bli en del av föreningen — fyll i dina uppgifter och välj medlemskapstyp.
            </p>
            <a href="{{ route('membership.apply') }}" wire:navigate
               class="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                <x-bootstrap-icon name="person-plus" class="size-4" />
                Bli medlem
            </a>
        </div>
    @else
        <div class="mb-6 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Mitt medlemskap</h2>
            <a href="{{ route('membership.edit') }}" wire:navigate
               class="text-sm text-complement hover:underline dark:text-complement-light">
                Redigera uppgifter
            </a>
        </div>

        {{-- Statuskort --}}
        <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center gap-4 px-5 py-4">
                <div class="flex-1">
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->member->full_name }}</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->typeLabel($this->member->membership_type) }}</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium {{ $this->statusClasses($this->member->status) }}">
                    @if($this->member->status === Member::STATUS_ACTIVE)
                        <x-bootstrap-icon name="check-circle-fill" class="size-3.5" />
                    @elseif($this->member->status === Member::STATUS_PENDING)
                        <x-bootstrap-icon name="clock" class="size-3.5" />
                    @else
                        <x-bootstrap-icon name="x-circle-fill" class="size-3.5" />
                    @endif
                    {{ $this->statusLabel($this->member->status) }}
                </span>
            </div>
            @if($this->member->status === Member::STATUS_PENDING)
                <div class="border-t border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
                    Din anmälan väntar på betalning. Du får mer information via e-post.
                </div>
            @elseif($this->member->status === Member::STATUS_EXPIRED)
                <div class="border-t border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
                    Ditt medlemskap har gått ut. Förnya för att åter bli aktiv.
                </div>
            @endif
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            {{-- Kontaktuppgifter --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800">
                    <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Kontaktuppgifter</h3>
                </div>
                <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <div class="grid grid-cols-2 gap-2 px-5 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">E-post</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->member->email ?? '—' }}</dd>
                    </div>
                    <div class="grid grid-cols-2 gap-2 px-5 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Telefon</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->member->phone ?? '—' }}</dd>
                    </div>
                    <div class="grid grid-cols-2 gap-2 px-5 py-3">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Adress</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">
                            @if($this->member->address)
                                {{ $this->member->address }}<br>
                                {{ $this->member->postal_code }} {{ $this->member->city }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    @if($this->member->date_of_birth)
                        <div class="grid grid-cols-2 gap-2 px-5 py-3">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Födelsedag</dt>
                            <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->member->date_of_birth->locale('sv')->isoFormat('D MMMM YYYY') }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="space-y-6">
                {{-- Senaste period --}}
                @php $currentPeriod = $this->member->membershipPeriods->first(); @endphp
                @if($currentPeriod)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800">
                            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Senaste period</h3>
                        </div>
                        <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <div class="grid grid-cols-2 gap-2 px-5 py-3">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">År</dt>
                                <dd class="text-sm font-medium text-zinc-900 dark:text-white">{{ $currentPeriod->year }}</dd>
                            </div>
                            <div class="grid grid-cols-2 gap-2 px-5 py-3">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Typ</dt>
                                <dd class="text-sm text-zinc-900 dark:text-white">{{ $this->typeLabel($currentPeriod->membership_type) }}</dd>
                            </div>
                            @if($currentPeriod->activated_at)
                                <div class="grid grid-cols-2 gap-2 px-5 py-3">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Aktiverat</dt>
                                    <dd class="text-sm text-zinc-900 dark:text-white">{{ $currentPeriod->activated_at->format('d/m Y') }}</dd>
                                </div>
                            @endif
                            @if($currentPeriod->expires_at)
                                <div class="grid grid-cols-2 gap-2 px-5 py-3">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Giltig till</dt>
                                    <dd class="text-sm text-zinc-900 dark:text-white">{{ $currentPeriod->expires_at->format('d/m Y') }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                @endif

                {{-- Sektioner --}}
                @if($this->member->sections->isNotEmpty())
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800">
                            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Mina grupper</h3>
                        </div>
                        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($this->member->sections as $section)
                                <li class="flex items-center justify-between px-5 py-3">
                                    <span class="text-sm text-zinc-900 dark:text-white">{{ $section->name }}</span>
                                    @php $role = $this->member->sectionRoles->firstWhere('section_id', $section->id); @endphp
                                    @if($role)
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $role->title }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
