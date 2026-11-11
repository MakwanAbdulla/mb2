<?php

use App\Mail\ContactConfirmationMail;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\Content\Page;
use App\Notifications\ContactMessageReceived;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kontakt');

new class extends Component {

    public string $name    = '';
    public string $email   = '';
    public string $phone   = '';
    public string $subject = '';
    public string $message = '';

    // Honeypot — must stay empty
    public string $website = '';

    public ?int  $formLoadedAt = null;
    public bool  $sent         = false;

    public function mount(): void
    {
        $this->formLoadedAt = time();

        if ($user = auth()->user()) {
            $this->name  = $user->name ?? '';
            $this->email = $user->email ?? '';
        }
    }

    #[Computed]
    public function page(): ?Page
    {
        return Page::published()->whereNull('path_prefix')->where('slug', 'kontakt')->first();
    }

    public function submit(): void
    {
        // Honeypot
        if (! empty($this->website)) {
            $this->sent = true;
            return;
        }

        // Minimum time — 3 seconds
        if ($this->formLoadedAt && (time() - $this->formLoadedAt) < 3) {
            $this->addError('general', 'Formuläret skickades för snabbt. Försök igen.');
            return;
        }

        $this->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:30',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|min:10|max:3000',
        ], [
            'name.required'    => 'Ange ditt namn.',
            'email.required'   => 'Ange din e-postadress.',
            'email.email'      => 'Ange en giltig e-postadress.',
            'subject.required' => 'Ange ett ämne.',
            'message.required' => 'Skriv ett meddelande.',
            'message.min'      => 'Meddelandet är för kort.',
        ]);

        $contact = ContactMessage::create([
            'name'       => trim($this->name),
            'email'      => trim($this->email),
            'phone'      => $this->phone ? trim($this->phone) : null,
            'subject'    => trim($this->subject),
            'message'    => trim($this->message),
            'ip_address' => request()->ip(),
            'status'     => ContactMessage::STATUS_NEW,
        ]);

        Notification::route('mail', config('mail.contact_address', config('mail.from.address')))
            ->notify(new ContactMessageReceived($contact));

        if ($contact->email) {
            Mail::to($contact->email)->queue(new ContactConfirmationMail($contact));
        }

        AuditLog::record('contact_message_submitted', 'members', $contact->name . ' — ' . $contact->subject, $contact);
        $this->reset('name', 'email', 'phone', 'subject', 'message');
        $this->sent = true;
    }
};
?>

<x-slot:header>
    <x-page-header title="Kontakt" />
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">

    {{-- CMS intro text (if any) --}}
    @if ($this->page?->content)
        <div class="prose prose-zinc max-w-none dark:prose-invert mb-8">
            {!! cms_content($this->page->content) !!}
        </div>
    @endif

    @if ($this->sent)
        <div class="rounded-xl border border-green-200 bg-green-50 dark:border-green-800/40 dark:bg-green-900/20 px-6 py-8 text-center">
            <x-bootstrap-icon name="check-circle-fill" class="size-10 text-green-500 mx-auto mb-3" />
            <h2 class="text-lg font-semibold text-green-800 dark:text-green-300">Tack för ditt meddelande!</h2>
            <p class="mt-1 text-sm text-green-700 dark:text-green-400">Vi återkommer så snart vi kan.</p>
            <button wire:click="$set('sent', false)" class="mt-4 text-sm text-green-600 dark:text-green-400 underline hover:no-underline">
                Skicka ett till
            </button>
        </div>
    @else
        @error('general')
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 dark:border-red-800/40 dark:bg-red-900/20 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                {{ $message }}
            </div>
        @enderror

        <form wire:submit="submit" class="space-y-5 max-w-xl">
            {{-- Honeypot --}}
            <div class="hidden" aria-hidden="true">
                <label for="website">Lämna detta fält tomt</label>
                <input type="text" id="website" wire:model="website" tabindex="-1" autocomplete="off" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <flux:label for="ctName">Namn <span class="text-red-500">*</span></flux:label>
                    <flux:input id="ctName" wire:model="name" autocomplete="name" />
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:label for="ctPhone">Telefon</flux:label>
                    <flux:input id="ctPhone" wire:model="phone" type="tel" autocomplete="tel" />
                </div>
            </div>

            <div>
                <flux:label for="ctEmail">E-post <span class="text-red-500">*</span></flux:label>
                <flux:input id="ctEmail" wire:model="email" type="email" autocomplete="email" />
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:label for="ctSubject">Ämne <span class="text-red-500">*</span></flux:label>
                <flux:input id="ctSubject" wire:model="subject" />
                @error('subject') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:label for="ctMessage">Meddelande <span class="text-red-500">*</span></flux:label>
                <flux:textarea id="ctMessage" wire:model="message" rows="6" />
                @error('message') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove>Skicka meddelande</span>
                <span wire:loading>Skickar…</span>
            </flux:button>
        </form>
    @endif
</div>
