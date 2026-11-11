<?php

use App\Models\InboxMailbox;
use App\Services\InboxReaderService;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\uses;

uses([WithFileUploads::class]);

new class extends Component {

    public int     $mailboxId   = 0;
    public string  $mode        = 'new';   // new | reply | forward
    public string  $to          = '';
    public string  $cc          = '';
    public bool    $showBcc     = false;
    public string  $bcc         = '';
    public string  $subject     = '';
    public string  $body        = '';
    public bool    $isHtml      = true;
    public ?string $draftId     = null;
    public ?string $threadId    = null;
    public ?string $inReplyTo   = null;
    public array   $uploads     = [];
    public bool    $sending     = false;
    public bool    $saving      = false;

    #[On('compose-open')]
    public function open(
        int     $mailboxId,
        string  $mode      = 'new',
        string  $to        = '',
        string  $cc        = '',
        string  $subject   = '',
        string  $body      = '',
        bool    $isHtml    = false,
        ?string $draftId   = null,
        ?string $threadId  = null,
        ?string $inReplyTo = null,
    ): void {
        $this->mailboxId  = $mailboxId;
        $this->mode       = $mode;
        $this->to         = $to;
        $this->cc         = $cc;
        $this->bcc        = '';
        $this->showBcc    = false;
        $this->subject    = $subject;
        $this->body       = $body;
        $this->isHtml     = $isHtml;
        $this->draftId    = $draftId;
        $this->threadId   = $threadId;
        $this->inReplyTo  = $inReplyTo;
        $this->uploads    = [];
        $this->resetValidation();
    }

    #[On('compose-close')]
    public function close(): void
    {
        $this->reset(['to','cc','bcc','subject','body','draftId','threadId','inReplyTo','uploads']);
        $this->isHtml  = false;
        $this->showBcc = false;
        $this->mailboxId = 0;
    }

    public function send(): void
    {
        $this->validate([
            'to'      => 'required|string',
            'subject' => 'required|string|max:500',
            'body'    => 'required|string|min:1',
        ]);

        $attachmentFiles = $this->buildAttachmentFiles();
        $reader          = new InboxReaderService(InboxMailbox::findOrFail($this->mailboxId));

        $ok = match ($this->mode) {
            'reply'   => $reader->replyExtended(
                threadId:        $this->threadId ?? '',
                to:              $this->to,
                cc:              $this->cc ?: null,
                subject:         $this->subject,
                body:            $this->body,
                isHtml:          $this->isHtml,
                inReplyTo:       $this->inReplyTo,
                attachmentFiles: $attachmentFiles,
            ),
            'forward' => $reader->forward(
                id:   $this->inReplyTo ?? '',
                to:   $this->to,
                cc:   $this->cc ?: null,
                note: $this->body,
            ),
            default   => $reader->sendNew(
                to:              $this->to,
                cc:              $this->cc ?: null,
                bcc:             $this->bcc ?: null,
                subject:         $this->subject,
                body:            $this->body,
                isHtml:          $this->isHtml,
                attachmentFiles: $attachmentFiles,
            ),
        };

        if ($ok) {
            if ($this->draftId) {
                $reader->deleteDraft($this->draftId);
            }
            $this->dispatch('compose-sent');
            $this->close();
        } else {
            $this->addError('body', 'Kunde inte skicka meddelandet. Försök igen.');
        }
    }

    public function saveDraft(): void
    {
        $reader  = new InboxReaderService(InboxMailbox::findOrFail($this->mailboxId));
        $newId   = $reader->saveDraft(
            to:              $this->to,
            cc:              $this->cc ?: null,
            bcc:             $this->bcc ?: null,
            subject:         $this->subject,
            body:            $this->body,
            isHtml:          $this->isHtml,
            attachmentFiles: $this->buildAttachmentFiles(),
            existingDraftId: $this->draftId,
            threadId:        $this->threadId,
            inReplyTo:       $this->inReplyTo,
        );

        if ($newId) {
            $this->draftId = $newId;
            $this->dispatch('draft-saved');
        }
    }

    public function removeAttachment(int $index): void
    {
        array_splice($this->uploads, $index, 1);
    }

    private function buildAttachmentFiles(): array
    {
        $files = [];
        foreach ($this->uploads as $upload) {
            $files[] = [
                'content'   => file_get_contents($upload->getRealPath()),
                'filename'  => $upload->getClientOriginalName(),
                'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
            ];
        }
        return $files;
    }
}; ?>

<div>
@if($mailboxId)
<div class="fixed inset-0 z-50 flex items-end justify-end p-4 sm:items-center sm:p-6 pointer-events-none">
    <div class="pointer-events-auto w-full max-w-2xl rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
         x-data="inboxCompose(@js($body))">

        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3.5 dark:border-zinc-700">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                @if($mode === 'reply') Svara
                @elseif($mode === 'forward') Vidarebefordra
                @else Nytt meddelande
                @endif
            </h3>
            <div class="flex items-center gap-3">
                <button wire:click="saveDraft" wire:loading.attr="disabled"
                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <span wire:loading.remove wire:target="saveDraft">Spara utkast</span>
                    <span wire:loading wire:target="saveDraft">Sparar…</span>
                </button>
                <button @click="$dispatch('compose-close')" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <x-bootstrap-icon name="x-lg" class="size-4" />
                </button>
            </div>
        </div>

        {{-- Fields --}}
        <div class="space-y-0 divide-y divide-zinc-100 dark:divide-zinc-800">

            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">Till</span>
                <input wire:model="to" type="text" class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="mottagare@exempel.se" />
            </div>

            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">Kopia</span>
                <input wire:model="cc" type="text" class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="valfri, komma-separerat" />
                @if(! $showBcc)
                    <button wire:click="$set('showBcc', true)"
                            class="shrink-0 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">+BCC</button>
                @endif
            </div>

            @if($showBcc)
            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">BCC</span>
                <input wire:model="bcc" type="text" class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="valfri" />
            </div>
            @endif

            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">Ämne</span>
                <input wire:model="subject" type="text" class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="Ämnesrad" />
            </div>

        </div>

        {{-- Body --}}
        <div class="px-5 py-3">
            <div wire:ignore>
                <input type="hidden" id="compose-trix-input-{{ $mailboxId }}"
                       x-ref="trixInput"
                       value="{{ $body }}"
                       x-on:trix-change="$wire.set('body', $event.target.value)" />
                <trix-editor input="compose-trix-input-{{ $mailboxId }}"
                             x-ref="trixEditor"
                             class="trix-content min-h-[14rem] rounded-lg border border-zinc-300 bg-white text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                             data-blob-url-template=""></trix-editor>
            </div>

            @error('body')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Attachments --}}
        @if(count($uploads) > 0)
        <div class="flex flex-wrap gap-2 border-t border-zinc-100 px-5 py-2 dark:border-zinc-800">
            @foreach($uploads as $i => $file)
                <div class="flex items-center gap-1 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                    <x-bootstrap-icon name="paperclip" class="size-3 shrink-0 text-zinc-400" />
                    <span class="text-zinc-600 dark:text-zinc-300">{{ $file->getClientOriginalName() }}</span>
                    <button wire:click="removeAttachment({{ $i }})" class="ml-1 text-zinc-400 hover:text-red-500">
                        <x-bootstrap-icon name="x-lg" class="size-2.5" />
                    </button>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center justify-between border-t border-zinc-200 px-5 py-3 dark:border-zinc-700">
            <div class="flex items-center gap-3">
                <button wire:click="send" wire:loading.attr="disabled" class="btn btn-primary btn-sm">
                    <span wire:loading.remove wire:target="send">
                        <x-bootstrap-icon name="send" class="size-3.5" />
                        Skicka
                    </span>
                    <span wire:loading wire:target="send">Skickar…</span>
                </button>
                <label class="cursor-pointer text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="Bifoga fil">
                    <x-bootstrap-icon name="paperclip" class="size-4" />
                    <input type="file" wire:model="uploads" multiple class="sr-only" />
                </label>
            </div>
            @error('to') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

    </div>
</div>

<script>
function inboxCompose(initialBody) {
    return {
        init() {
            this.$nextTick(() => {
                if (initialBody && this.$refs.trixEditor?.editor) {
                    this.$refs.trixEditor.editor.loadHTML(initialBody);
                }
            });
        }
    }
}
</script>
@endif
</div>
