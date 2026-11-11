<?php

use App\Enums\SignMethod;
use App\Models\AuditLog;
use App\Models\Signing\SigningRequest;
use App\Services\Signing\SigningService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Nytt signeringsärende');

new class extends Component {
    use WithFileUploads;

    public string $name = '';

    public string $comment = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    /** @var list<array{name: string, email: string, phone: string, sign_method: string, role_label: string}> */
    public array $signers = [];

    public bool $sendReminders = true;

    public bool $sendReceipt = true;

    public ?string $sendError = null;

    public function mount(): void
    {
        abort_unless(SigningRequest::canBeCreatedBy(Auth::user()), 403);
        abort_unless(config('services.docsign.api_key'), 404);

        $this->addSigner();
    }

    public function addSigner(): void
    {
        $this->signers[] = ['name' => '', 'email' => '', 'phone' => '', 'sign_method' => SignMethod::BankId->value, 'role_label' => ''];
    }

    public function removeSigner(int $index): void
    {
        unset($this->signers[$index]);
        $this->signers = array_values($this->signers);
    }

    public function send(): void
    {
        abort_unless(SigningRequest::canBeCreatedBy(Auth::user()), 403);

        $this->validate([
            'name' => 'required|string|max:200',
            'comment' => 'nullable|string|max:2000',
            'files' => 'required|array|min:1|max:5',
            'files.*' => 'file|mimes:pdf|max:20480',
            'signers' => 'required|array|min:1|max:20',
            'signers.*.name' => 'required|string|max:200',
            'signers.*.email' => 'nullable|required_without:signers.*.phone|email|max:200',
            'signers.*.phone' => 'nullable|required_without:signers.*.email|string|max:30',
            'signers.*.sign_method' => 'required|in:bankid,email',
            'signers.*.role_label' => 'nullable|string|max:100',
        ], [
            'name.required' => 'Ge ärendet ett namn.',
            'files.required' => 'Bifoga minst en PDF.',
            'files.max' => 'Högst fem filer.',
            'files.*.mimes' => 'Bara PDF-filer kan skickas för signering.',
            'files.*.max' => 'En fil får vara högst 20 MB.',
            'signers.required' => 'Lägg till minst en undertecknare.',
            'signers.*.name.required' => 'Ange namn.',
            'signers.*.email.required_without' => 'Ange e-post eller telefon.',
            'signers.*.email.email' => 'Ange en giltig e-postadress.',
            'signers.*.phone.required_without' => 'Ange e-post eller telefon.',
        ]);

        $request = app(SigningService::class)->createDraft(
            name: $this->name,
            comment: $this->comment,
            signers: $this->signers,
            files: array_map(fn ($file) => ['name' => $file->getClientOriginalName(), 'contents' => $file->get()], array_values($this->files)),
            createdBy: Auth::id(),
            sendReminders: $this->sendReminders,
            sendReceipt: $this->sendReceipt,
        );

        AuditLog::record('signing_request_created', 'signing', $request->name, $request);

        try {
            app(SigningService::class)->send($request);
            session()->flash('success', 'Skickat för signering. Undertecknarna får ett mejl från DocSign.');
        } catch (Throwable $e) {
            session()->flash('error', 'Ärendet sparades men kunde inte skickas: '.$e->getMessage());
        }

        $this->redirect(route('admin.signing.show', $request), navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header title="Nytt signeringsärende" subtitle="Skicka ett eller flera PDF-dokument för signering med BankID." permission="signing.create" />
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
    <form wire:submit="send" class="space-y-6">

        <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <flux:label>Namn på ärendet <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="name" placeholder="t.ex. Protokoll styrelsemöte 12/3" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Visas för undertecknarna.</p>
                @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:label>Meddelande till undertecknarna</flux:label>
                <flux:textarea wire:model="comment" rows="3" />
                @error('comment') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:label>Dokument (PDF) <span class="text-red-500">*</span></flux:label>
                <input type="file" wire:model="files" multiple accept=".pdf,application/pdf"
                       class="block w-full cursor-pointer text-sm text-zinc-500 file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-800 dark:file:text-zinc-300 dark:hover:file:bg-zinc-700" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Högst fem filer, 20 MB styck.</p>
                @error('files') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @error('files.*') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Undertecknare</h2>
                <flux:button wire:click="addSigner" type="button" size="sm" variant="ghost">
                    <x-bootstrap-icon name="plus" class="size-4" /> Lägg till
                </flux:button>
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Om e-postadressen tillhör en användare i Membrum kopplas undertecknaren till användaren, och kan då också signera direkt från sin startsida. Annars blir det en extern undertecknare.
            </p>
            @error('signers') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

            @foreach ($signers as $i => $signer)
                <div wire:key="signer-{{ $i }}" class="grid gap-3 rounded-lg border border-zinc-100 p-3 dark:border-zinc-800 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <flux:label>Namn</flux:label>
                        <flux:input wire:model="signers.{{ $i }}.name" />
                        @error("signers.$i.name") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <flux:label>E-post</flux:label>
                        <flux:input wire:model="signers.{{ $i }}.email" type="email" />
                        @error("signers.$i.email") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <flux:label>Telefon</flux:label>
                        <flux:input wire:model="signers.{{ $i }}.phone" placeholder="07x-xxx xx xx" />
                        @error("signers.$i.phone") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <flux:label>Signera med</flux:label>
                        <flux:select wire:model="signers.{{ $i }}.sign_method">
                            @foreach (SignMethod::cases() as $method)
                                <option value="{{ $method->value }}">{{ $method->label() }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="sm:col-span-3">
                        <flux:label>Roll (valfri)</flux:label>
                        <flux:input wire:model="signers.{{ $i }}.role_label" placeholder="t.ex. Justerare" />
                    </div>
                    <div class="flex items-end sm:col-span-1">
                        @if (count($signers) > 1)
                            <flux:button wire:click="removeSigner({{ $i }})" type="button" size="sm" variant="ghost">
                                <x-bootstrap-icon name="trash" class="size-4" /> Ta bort
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="space-y-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:checkbox wire:model="sendReminders" label="Skicka påminnelser till den som inte har signerat" />
            <flux:checkbox wire:model="sendReceipt" label="Skicka kvitto till undertecknarna när de har signerat" />
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                @if (config('services.docsign.demo'))
                    <strong>Demoläge:</strong> skickas som ett gratis testdokument utan riktig signering.
                @else
                    Kostar 1,25 kr per undertecknare, och dras när de signerar.
                @endif
            </p>
        </div>

        <div class="flex items-center justify-end gap-3">
            <flux:button href="{{ route('admin.signing.index') }}" wire:navigate variant="ghost">Avbryt</flux:button>
            <flux:button type="submit" variant="primary">
                <span wire:loading.remove wire:target="send">Skicka för signering</span>
                <span wire:loading wire:target="send">Skickar …</span>
            </flux:button>
        </div>
    </form>
</div>
