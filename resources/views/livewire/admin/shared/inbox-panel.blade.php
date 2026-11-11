<?php

use App\Models\InboxMailbox;
use App\Services\InboxReaderService;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\uses;

uses([WithFileUploads::class]);

new class extends Component {

    public int $mailboxId;

    // Navigation
    public string $folder      = 'unread'; // unread | all | sent | drafts
    public string $searchQuery = '';
    public bool   $searching   = false;

    // List + pagination
    public array   $messages   = [];
    public ?string $nextToken  = null;
    public bool    $loadingMore = false;

    // Drafts (separate list from messages)
    public array $drafts = [];

    // Open state
    public ?string $openId     = null;
    public ?array  $openMsg    = null;
    public ?string $openThread = null;
    public array   $threadMsgs = [];

    // CRM
    public ?array $senderMember = null;

    // Triage
    public bool   $triageOpen        = false;
    public string $triageType        = 'issue'; // 'issue' | 'motion'
    public string $triageTitle       = '';
    public string $triageDescription = '';

    // Compose
    public bool    $composing        = false;
    public string  $composeMode      = 'new';
    public string  $composeTo        = '';
    public string  $composeCC        = '';
    public bool    $composeShowCC    = false;
    public string  $composeBCC       = '';
    public bool    $composeShowBCC   = false;
    public string  $composeSubject   = '';
    public string  $composeBody      = '';
    public bool    $composeIsHtml    = true;
    public ?string $composeDraftId   = null;
    public ?string $composeThreadId  = null;
    public ?string $composeInReplyTo = null;
    public array   $composeUploads   = [];

    public function mount(): void
    {
        $this->loadMessages();
    }

    private function reader(): InboxReaderService
    {
        return new InboxReaderService(InboxMailbox::findOrFail($this->mailboxId));
    }

    // ── Loading ───────────────────────────────────────────────────────────────

    public function loadMessages(): void
    {
        $this->openId     = null;
        $this->openMsg    = null;
        $this->openThread = null;
        $this->threadMsgs = [];

        if ($this->folder === 'drafts') {
            $this->drafts    = $this->reader()->listDrafts();
            $this->messages  = [];
            $this->nextToken = null;
            return;
        }

        $this->drafts = [];

        $result          = $this->searching && $this->searchQuery
            ? $this->reader()->search($this->searchQuery, 50)
            : $this->reader()->listMessages($this->folder, 50);

        $this->messages  = $result['messages'];
        $this->nextToken = $result['nextPageToken'];
    }

    public function loadMore(): void
    {
        if (! $this->nextToken) {
            return;
        }

        $result = $this->searching && $this->searchQuery
            ? $this->reader()->search($this->searchQuery, 50, $this->nextToken)
            : $this->reader()->listMessages($this->folder, 50, $this->nextToken);

        $this->messages  = [...$this->messages, ...$result['messages']];
        $this->nextToken = $result['nextPageToken'];
    }

    public function setView(string $view): void
    {
        $this->folder      = $view;
        $this->searching   = false;
        $this->searchQuery = '';
        $this->loadMessages();
    }

    public function search(): void
    {
        if (trim($this->searchQuery) === '') {
            $this->searching = false;
            $this->loadMessages();
            return;
        }
        $this->searching = true;
        $this->loadMessages();
    }

    public function clearSearch(): void
    {
        $this->searchQuery = '';
        $this->searching   = false;
        $this->loadMessages();
    }

    // ── Message actions ───────────────────────────────────────────────────────

    public function open(string $id): void
    {
        if ($this->openId === $id && ! $this->openThread) {
            $this->openId       = null;
            $this->openMsg      = null;
            $this->senderMember = null;
            return;
        }

        $this->openId     = $id;
        $this->openThread = null;
        $this->threadMsgs = [];
        $this->openMsg    = $this->reader()->getMessage($id);

        $this->senderMember = null;
        if ($this->openMsg && ! empty($this->openMsg['from_email'])) {
            $member = \App\Models\Membership\Member::where('email', $this->openMsg['from_email'])
                ->first(['id', 'first_name', 'last_name', 'status']);
            if ($member) {
                $this->senderMember = [
                    'id'     => $member->id,
                    'name'   => $member->first_name . ' ' . $member->last_name,
                    'status' => $member->status,
                ];
            }
        }
    }

    public function loadThread(string $threadId): void
    {
        $this->openThread = $threadId;
        $this->threadMsgs = $this->reader()->getThread($threadId);
    }

    public function closeThread(): void
    {
        $this->openThread = null;
        $this->threadMsgs = [];
    }

    // ── Triage ────────────────────────────────────────────────────────────────

    public function openTriage(): void
    {
        $this->triageType        = 'issue';
        $this->triageTitle       = $this->openMsg['subject'] ?? '';
        $this->triageDescription = $this->openMsg['body_text']
            ?? (isset($this->openMsg['body_html']) ? trim(strip_tags($this->openMsg['body_html'])) : '');
        $this->triageOpen        = true;
    }

    public function closeTriage(): void
    {
        $this->triageOpen = false;
    }

    public function saveTriage(): void
    {
        $this->validate(['triageTitle' => 'required|string|max:500']);

        $senderEmail = $this->openMsg['from_email'] ?? null;
        $createdBy   = \Illuminate\Support\Facades\Auth::id();

        if ($senderEmail) {
            $senderUser = \App\Models\User::where('email', $senderEmail)->value('id');
            if ($senderUser) {
                $createdBy = $senderUser;
            }
        }

        \App\Models\Submission::create([
            'type'                 => $this->triageType,
            'title'                => $this->triageTitle,
            'body'                 => $this->triageDescription ?: null,
            'status'               => 'submitted',
            'source_gmail_id'      => $this->openMsg['gmail_id'],
            'source_gmail_subject' => $this->openMsg['subject'] ?? null,
            'source_gmail_from'    => $senderEmail,
            'source_mailbox_id'    => $this->mailboxId,
            'created_by'           => $createdBy,
        ]);

        $this->reader()->addTriageLabel($this->openMsg['gmail_id']);
        $this->closeTriage();
        $this->dispatch('notify', type: 'success', message: 'Ärende registrerat.');
    }

    public function markRead(string $id): void
    {
        $this->reader()->markRead($id);
        $this->afterAction($id);
    }

    public function markUnread(string $id): void
    {
        $this->reader()->markUnread($id);
        $this->afterAction($id);
    }

    public function trash(string $id): void
    {
        $this->reader()->trash($id);
        $this->afterAction($id);
    }

    private function afterAction(string $id): void
    {
        if ($this->openId === $id) {
            $this->openId     = null;
            $this->openMsg    = null;
            $this->openThread = null;
        }
        $this->loadMessages();
    }

    // ── Draft actions ─────────────────────────────────────────────────────────

    public function openDraft(string $draftId): void
    {
        $draft = $this->reader()->getDraft($draftId);
        if (! $draft) {
            return;
        }

        $this->openComposeWith(
            mode:      'new',
            to:        $draft['to'] ?? '',
            cc:        $draft['cc'] ?? '',
            subject:   $draft['subject'] ?? '',
            body:      $draft['body_html'] ?? $draft['body_text'] ?? '',
            draftId:   $draftId,
            threadId:  $draft['thread_id'] ?? null,
        );
    }

    public function deleteDraft(string $draftId): void
    {
        $this->reader()->deleteDraft($draftId);
        $this->drafts = $this->reader()->listDrafts();
    }

    public function sendDraft(string $draftId): void
    {
        $ok = $this->reader()->sendDraft($draftId);
        if ($ok) {
            $this->loadMessages();
        }
    }

    // ── Compose ───────────────────────────────────────────────────────────────

    public function compose(): void
    {
        $this->openComposeWith(mode: 'new');
    }

    public function startReply(string $id): void
    {
        $msg = $this->openId === $id ? $this->openMsg : $this->reader()->getMessage($id);
        if (! $msg) {
            return;
        }

        $this->openComposeWith(
            mode:      'reply',
            to:        $msg['reply_to'] ?? $msg['from_email'],
            subject:   str_starts_with((string) $msg['subject'], 'Re:') ? $msg['subject'] : 'Re: ' . $msg['subject'],
            threadId:  $msg['thread_id'],
            inReplyTo: $msg['message_id'],
        );
    }

    public function startForward(string $id): void
    {
        $msg = $this->openId === $id ? $this->openMsg : $this->reader()->getMessage($id);
        if (! $msg) {
            return;
        }

        $this->openComposeWith(
            mode:      'forward',
            subject:   str_starts_with((string) $msg['subject'], 'Fwd:') ? $msg['subject'] : 'Fwd: ' . $msg['subject'],
            inReplyTo: $id,
        );
    }

    private function openComposeWith(
        string  $mode      = 'new',
        string  $to        = '',
        string  $cc        = '',
        string  $subject   = '',
        string  $body      = '',
        ?string $draftId   = null,
        ?string $threadId  = null,
        ?string $inReplyTo = null,
    ): void {
        $this->composeMode      = $mode;
        $this->composeTo        = $to;
        $this->composeCC        = $cc;
        $this->composeShowCC    = $cc !== '';
        $this->composeBCC       = '';
        $this->composeShowBCC   = false;
        $this->composeSubject   = $subject;
        $this->composeBody      = $body;
        $this->composeIsHtml    = true;
        $this->composeDraftId   = $draftId;
        $this->composeThreadId  = $threadId;
        $this->composeInReplyTo = $inReplyTo;
        $this->composeUploads   = [];
        $this->composing        = true;
    }

    public function cancelCompose(): void
    {
        $this->composing = false;
    }

    public function sendCompose(): void
    {
        $this->validate([
            'composeTo'      => 'required|string',
            'composeSubject' => 'required|string|max:500',
            'composeBody'    => 'required|string|min:1',
        ]);

        $attachmentFiles = $this->buildAttachmentFiles();
        $reader          = $this->reader();

        $ok = match ($this->composeMode) {
            'reply'   => $reader->replyExtended(
                threadId:        $this->composeThreadId ?? '',
                to:              $this->composeTo,
                cc:              $this->composeCC ?: null,
                subject:         $this->composeSubject,
                body:            $this->composeBody,
                isHtml:          $this->composeIsHtml,
                inReplyTo:       $this->composeInReplyTo,
                attachmentFiles: $attachmentFiles,
            ),
            'forward' => $reader->forward(
                id:   $this->composeInReplyTo ?? '',
                to:   $this->composeTo,
                cc:   $this->composeCC ?: null,
                note: $this->composeBody,
            ),
            default   => $reader->sendNew(
                to:              $this->composeTo,
                cc:              $this->composeCC ?: null,
                bcc:             $this->composeBCC ?: null,
                subject:         $this->composeSubject,
                body:            $this->composeBody,
                isHtml:          $this->composeIsHtml,
                attachmentFiles: $attachmentFiles,
            ),
        };

        if ($ok) {
            if ($this->composeDraftId) {
                $reader->deleteDraft($this->composeDraftId);
            }
            $this->cancelCompose();
            $this->loadMessages();
        } else {
            $this->addError('composeBody', 'Kunde inte skicka meddelandet. Försök igen.');
        }
    }

    public function saveDraft(): void
    {
        $reader = $this->reader();
        $newId  = $reader->saveDraft(
            to:              $this->composeTo,
            cc:              $this->composeCC ?: null,
            bcc:             $this->composeBCC ?: null,
            subject:         $this->composeSubject,
            body:            $this->composeBody,
            isHtml:          $this->composeIsHtml,
            attachmentFiles: $this->buildAttachmentFiles(),
            existingDraftId: $this->composeDraftId,
            threadId:        $this->composeThreadId,
            inReplyTo:       $this->composeInReplyTo,
        );

        if ($newId) {
            $this->composeDraftId = $newId;
        }
    }

    public function removeAttachment(int $index): void
    {
        array_splice($this->composeUploads, $index, 1);
    }

    private function buildAttachmentFiles(): array
    {
        $files = [];
        foreach ($this->composeUploads as $upload) {
            $files[] = [
                'content'   => file_get_contents($upload->getRealPath()),
                'filename'  => $upload->getClientOriginalName(),
                'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
            ];
        }
        return $files;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function attachmentUrl(string $messageId, string $attachmentId): string
    {
        return URL::temporarySignedRoute(
            'inbox.attachment.download',
            now()->addHours(2),
            ['mailboxId' => $this->mailboxId, 'messageId' => $messageId, 'attachmentId' => $attachmentId]
        );
    }
}; ?>

<div>
    {{-- Toolbar --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        @if($composing)
            <button wire:click="cancelCompose" class="btn btn-ghost btn-sm">
                <x-bootstrap-icon name="arrow-left" class="size-3.5" />
                Tillbaka
            </button>
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                @if($composeMode === 'reply') Svara
                @elseif($composeMode === 'forward') Vidarebefordra
                @else Nytt meddelande
                @endif
            </span>
            <div class="ml-auto">
                <button wire:click="saveDraft" class="btn btn-ghost btn-sm">Spara utkast</button>
            </div>
        @else
            {{-- Tabs --}}
            <div class="flex rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                @foreach(['unread' => 'Olästa', 'all' => 'Alla', 'sent' => 'Skickade', 'drafts' => 'Utkast'] as $v => $label)
                    <button wire:click="setView('{{ $v }}')"
                            class="rounded-md px-3 py-1.5 font-medium transition-colors
                                   {{ $folder === $v
                                      ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
                                      : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                        {{ $label }}
                        @if($v === 'unread' && count($messages) > 0 && $folder === 'unread')
                            <span class="ml-1 rounded-full bg-complement px-1.5 py-0.5 text-[10px] text-white">{{ count($messages) }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Search --}}
            <div class="flex flex-1 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800">
                <x-bootstrap-icon name="search" class="size-3.5 shrink-0 text-zinc-400" />
                <input wire:model="searchQuery"
                       @keydown.enter="$wire.search()"
                       type="text"
                       class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="Sök i inkorg…" />
                @if($searching)
                    <button wire:click="clearSearch" class="text-zinc-400 hover:text-zinc-600">
                        <x-bootstrap-icon name="x-circle" class="size-3.5" />
                    </button>
                @endif
            </div>

            {{-- Actions --}}
            <button wire:click="compose" class="btn btn-primary btn-sm">
                <x-bootstrap-icon name="pencil-square" class="size-3.5" />
                Skriv
            </button>
            <button wire:click="loadMessages" class="btn btn-ghost btn-sm">
                <x-bootstrap-icon name="arrow-clockwise" class="size-4" />
            </button>
        @endif
    </div>

    @if($composing)
    {{-- Inline compose form --}}
    {{-- grid-rows: fields=auto, body=1fr, footer=auto — gives body a definite height so trix-editor can use h-full --}}
    <div class="grid h-[calc(100dvh-12rem)] grid-rows-[auto_1fr_auto] overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
         x-data="inboxCompose(@js($composeBody))">

        {{-- Fields --}}
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">

            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">Till</span>
                <input wire:model="composeTo" type="text"
                       class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="mottagare@exempel.se" />
                @if(! $composeShowCC)
                    <button wire:click="$set('composeShowCC', true)"
                            class="shrink-0 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">+Kopia</button>
                @endif
                @if(! $composeShowBCC)
                    <button wire:click="$set('composeShowBCC', true)"
                            class="shrink-0 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">+BCC</button>
                @endif
            </div>

            @if($composeShowCC)
            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">Kopia</span>
                <input wire:model="composeCC" type="text"
                       class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="valfri, komma-separerat" />
                <button wire:click="$set('composeShowCC', false)"
                        class="shrink-0 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">−Kopia</button>
            </div>
            @endif

            @if($composeShowBCC)
            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">BCC</span>
                <input wire:model="composeBCC" type="text"
                       class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="valfri" />
                <button wire:click="$set('composeShowBCC', false)"
                        class="shrink-0 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">−BCC</button>
            </div>
            @endif

            <div class="flex items-center gap-2 px-5 py-2">
                <span class="w-8 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">Ämne</span>
                <input wire:model="composeSubject" type="text"
                       class="flex-1 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                       placeholder="Ämnesrad" />
            </div>

        </div>

        {{-- Body (1fr grid row — definite height from grid, flex col to pin toolbar above scroll area) --}}
        <div class="flex flex-col overflow-hidden px-4 pt-3">
            <div wire:ignore class="flex min-h-0 flex-1 flex-col">
                {{-- Toolbar declared here so it stays above the scroll area --}}
                <trix-toolbar id="inbox-trix-toolbar" class="rounded-t-lg border border-b-0 border-zinc-300 dark:border-zinc-600"></trix-toolbar>
                {{-- Scroll container — only the editor content scrolls, not the toolbar --}}
                <div class="min-h-0 flex-1 cursor-text overflow-y-auto rounded-b-lg border border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800"
                     @click="$refs.trixEditor.focus()">
                    <input type="hidden" id="inbox-trix-input"
                           x-ref="trixInput"
                           value="{{ $composeBody }}" />
                    <trix-editor toolbar="inbox-trix-toolbar"
                                 input="inbox-trix-input"
                                 x-ref="trixEditor"
                                 class="trix-content block w-full min-h-full text-sm text-zinc-900 dark:text-white"
                                 data-blob-url-template=""
                                 x-on:trix-change="$wire.set('composeBody', $refs.trixInput.value)"></trix-editor>
                </div>
            </div>
            @error('composeBody')
                <p class="text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Footer (auto grid row) --}}
        <div class="border-t border-zinc-200 dark:border-zinc-700">
            @if(count($composeUploads) > 0)
            <div class="flex flex-wrap gap-2 border-b border-zinc-100 px-5 py-2 dark:border-zinc-800">
                @foreach($composeUploads as $i => $file)
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
            <div class="flex items-center justify-between px-5 py-3">
            <div class="flex items-center gap-3">
                <button wire:click="sendCompose" wire:loading.attr="disabled" class="btn btn-primary btn-sm whitespace-nowrap">
                    <span wire:loading.remove wire:target="sendCompose" class="flex items-center gap-1.5">
                        <x-bootstrap-icon name="send" class="size-3.5 shrink-0" />
                        Skicka
                    </span>
                    <span wire:loading wire:target="sendCompose">Skickar…</span>
                </button>
                <label class="cursor-pointer text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="Bifoga fil">
                    <x-bootstrap-icon name="paperclip" class="size-5" />
                    <input type="file" wire:model="composeUploads" multiple class="sr-only" />
                </label>
            </div>
            @error('composeTo') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
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
                // Block Trix's own file-attach flow — attachments handled by our upload button
                this.$refs.trixEditor?.addEventListener('trix-file-accept', e => e.preventDefault());
            }
        }
    }
    </script>

    @else
    {{-- Drafts view --}}
    @if($folder === 'drafts')
        @if(empty($drafts))
            <div wire:key="drafts-empty" class="rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <x-bootstrap-icon name="file-earmark-text" class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga utkast.</p>
            </div>
        @else
            <div wire:key="drafts-list" class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                @foreach($drafts as $draft)
                    <div wire:key="{{ $draft['draft_id'] }}" class="flex items-start gap-3 border-b border-zinc-100 bg-white px-4 py-3 last:border-0 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800/50">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-medium text-zinc-500 dark:text-zinc-400">
                                    Till: {{ $draft['to'] ?? '(ingen mottagare)' }}
                                </span>
                                <span class="shrink-0 text-xs text-zinc-400">
                                    {{ \Carbon\Carbon::createFromTimestamp($draft['updated_at'])->locale('sv')->diffForHumans() }}
                                </span>
                            </div>
                            <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $draft['subject'] ?? '(inget ämne)' }}
                            </div>
                            <div class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $draft['snippet'] }}
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button wire:click="openDraft('{{ $draft['draft_id'] }}')"
                                    class="text-xs text-complement hover:underline dark:text-complement-light">Redigera</button>
                            <button wire:click="sendDraft('{{ $draft['draft_id'] }}')"
                                    wire:confirm="Skicka detta utkast?"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Skicka</button>
                            <button wire:click="deleteDraft('{{ $draft['draft_id'] }}')"
                                    wire:confirm="Ta bort utkastet?"
                                    class="text-xs text-red-500 hover:text-red-700">Ta bort</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    {{-- Messages view --}}
    @else
        @if($searching)
            <p class="mb-2 text-xs text-zinc-400 dark:text-zinc-500">
                Sökresultat för "{{ $searchQuery }}" — {{ count($messages) }} träffar
            </p>
        @endif

        @if(empty($messages))
            <div wire:key="messages-empty" class="rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <x-bootstrap-icon name="inbox" class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $searching ? 'Inga meddelanden matchar sökningen.' : 'Inga meddelanden.' }}
                </p>
            </div>
        @else
            <div wire:key="messages-list" class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                @foreach($messages as $msg)
                    @php
                        $isOpen    = $openId === $msg['gmail_id'];
                        $isSent    = $folder === 'sent';
                        $primary   = $isSent ? ($msg['to'] ?? $msg['from_email']) : ($msg['from_name'] ?? $msg['from_email']);
                        $secondary = $isSent ? null : ($msg['from_name'] ? $msg['from_email'] : null);
                    @endphp
                    <div wire:key="{{ $msg['gmail_id'] }}" class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">

                        {{-- Row --}}
                        <div wire:click="open('{{ $msg['gmail_id'] }}')"
                             class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3 hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800/50
                                    {{ in_array('UNREAD', $msg['labels'] ?? []) ? 'font-medium' : '' }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-zinc-900 dark:text-white">
                                        {{ $primary }}
                                        @if($secondary)
                                            <span class="ml-1 font-normal text-xs text-zinc-400 dark:text-zinc-500">{{ $secondary }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ \Carbon\Carbon::createFromTimestamp($msg['received_at'])->locale('sv')->diffForHumans() }}
                                    </span>
                                </div>
                                <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $msg['subject'] ?? '(ingen ämnesrad)' }}
                                </div>
                                @if(! $isOpen)
                                    <div class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ $msg['snippet'] }}
                                    </div>
                                @endif
                            </div>
                            <x-bootstrap-icon
                                name="{{ $isOpen ? 'chevron-up' : 'chevron-down' }}"
                                class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                        </div>

                        {{-- Expanded --}}
                        @if($isOpen && $openMsg)
                            <div class="border-t border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/30">

                                {{-- CRM chip --}}
                                @if($senderMember)
                                <div class="flex items-center gap-2 border-b border-zinc-100 px-5 py-2 dark:border-zinc-800">
                                    <x-bootstrap-icon name="person-fill" class="size-3.5 shrink-0 text-zinc-400" />
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Avsändaren är registrerad:</span>
                                    <a href="{{ route('admin.members.show', $senderMember['id']) }}"
                                       class="text-xs font-medium text-complement hover:underline dark:text-complement-light">
                                        {{ $senderMember['name'] }}
                                    </a>
                                    @if($senderMember['status'] !== 'active')
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                            {{ $senderMember['status'] }}
                                        </span>
                                    @endif
                                </div>
                                @endif

                                {{-- Thread view --}}
                                @if($openThread && count($threadMsgs) > 0)
                                    <div class="border-b border-zinc-100 bg-zinc-100/50 px-5 py-2 dark:border-zinc-800 dark:bg-zinc-800/50">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                                Tråd · {{ count($threadMsgs) }} meddelanden
                                            </span>
                                            <button wire:click="closeThread" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                                Dölj tråd
                                            </button>
                                        </div>
                                    </div>
                                    @foreach($threadMsgs as $tmsg)
                                        <div x-data="{ open: {{ $loop->last ? 'true' : 'false' }} }"
                                             class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                            <button @click="open = !open"
                                                    class="flex w-full items-center gap-3 px-5 py-2.5 text-left hover:bg-zinc-100 dark:hover:bg-zinc-700/50">
                                                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                                    {{ $tmsg['from_name'] ?? $tmsg['from_email'] }}
                                                </span>
                                                <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                                    {{ \Carbon\Carbon::createFromTimestamp($tmsg['received_at'])->locale('sv')->diffForHumans() }}
                                                </span>
                                                <x-bootstrap-icon name="chevron-right" class="ml-auto size-3 text-zinc-400 transition-transform" x-bind:class="{ 'rotate-90': open }" />
                                            </button>
                                            <div x-show="open" x-cloak class="px-5 pb-4">
                                                @if($tmsg['body_html'])
                                                    <div class="prose prose-sm dark:prose-invert max-w-none overflow-auto rounded border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                                        {!! $tmsg['body_html'] !!}
                                                    </div>
                                                @elseif($tmsg['body_text'])
                                                    <pre class="overflow-auto whitespace-pre-wrap rounded border border-zinc-200 bg-white p-4 font-sans text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">{{ $tmsg['body_text'] }}</pre>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                @else
                                    {{-- Single message body --}}
                                    <div class="px-5 py-4">
                                        @if($openMsg['body_html'])
                                            <div class="prose prose-sm dark:prose-invert max-w-none overflow-auto rounded border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                                {!! $openMsg['body_html'] !!}
                                            </div>
                                        @elseif($openMsg['body_text'])
                                            <pre class="overflow-auto whitespace-pre-wrap rounded border border-zinc-200 bg-white p-4 font-sans text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">{{ $openMsg['body_text'] }}</pre>
                                        @else
                                            <p class="text-sm text-zinc-400 dark:text-zinc-500">(Inget innehåll)</p>
                                        @endif
                                    </div>
                                @endif

                                {{-- Attachments --}}
                                @if(! empty($openMsg['attachments']))
                                    <div class="flex flex-wrap gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                                        @foreach($openMsg['attachments'] as $att)
                                            @if($att['attachment_id'])
                                                <a href="{{ $this->attachmentUrl($openMsg['gmail_id'], $att['attachment_id']) }}"
                                                   class="flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
                                                   download="{{ $att['filename'] }}">
                                                    <x-bootstrap-icon name="paperclip" class="size-3.5 shrink-0" />
                                                    {{ $att['filename'] }}
                                                    @if($att['size'])
                                                        <span class="text-zinc-400">({{ round($att['size'] / 1024) }} KB)</span>
                                                    @endif
                                                </a>
                                            @else
                                                <div class="flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900">
                                                    <x-bootstrap-icon name="paperclip" class="size-3.5 shrink-0" />
                                                    {{ $att['filename'] }}
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Actions --}}
                                <div class="flex flex-wrap gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                                    <button wire:click="openTriage" class="btn btn-ghost btn-sm">
                                        <x-bootstrap-icon name="folder-plus" class="size-4" />
                                        Registrera ärende
                                    </button>
                                    <button wire:click="startReply('{{ $msg['gmail_id'] }}')" class="btn btn-ghost btn-sm">
                                        <x-bootstrap-icon name="reply" class="size-4" />
                                        Svara
                                    </button>
                                    <button wire:click="startForward('{{ $msg['gmail_id'] }}')" class="btn btn-ghost btn-sm">
                                        <x-bootstrap-icon name="forward" class="size-4" />
                                        Vidarebefordra
                                    </button>
                                    @if(! $openThread)
                                        <button wire:click="loadThread('{{ $openMsg['thread_id'] }}')" class="btn btn-ghost btn-sm">
                                            <x-bootstrap-icon name="chat-left-text" class="size-4" />
                                            Visa tråd
                                        </button>
                                    @endif
                                    @if(in_array('UNREAD', $openMsg['labels'] ?? []))
                                        <button wire:click="markRead('{{ $msg['gmail_id'] }}')" class="btn btn-ghost btn-sm">
                                            <x-bootstrap-icon name="check2" class="size-4" />
                                            Markera läst
                                        </button>
                                    @else
                                        <button wire:click="markUnread('{{ $msg['gmail_id'] }}')" class="btn btn-ghost btn-sm">
                                            <x-bootstrap-icon name="envelope" class="size-4" />
                                            Markera oläst
                                        </button>
                                    @endif
                                    <button wire:click="trash('{{ $msg['gmail_id'] }}')"
                                            wire:confirm="Flytta till papperskorgen?"
                                            class="btn btn-ghost btn-sm text-red-600 hover:text-red-700 dark:text-red-400">
                                        <x-bootstrap-icon name="trash" class="size-4" />
                                        Ta bort
                                    </button>
                                </div>

                            </div>
                        @endif

                    </div>
                @endforeach
            </div>

            {{-- Load more --}}
            @if($nextToken)
                <div class="mt-3 text-center">
                    <button wire:click="loadMore" class="btn btn-ghost btn-sm">
                        <span wire:loading.remove wire:target="loadMore">Ladda fler</span>
                        <span wire:loading wire:target="loadMore">Laddar…</span>
                    </button>
                </div>
            @endif
        @endif
    @endif
    @endif

    {{-- Triage modal --}}
    @if($triageOpen && $openMsg)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
        <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">

            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4 dark:border-zinc-800">
                <h3 class="font-semibold text-zinc-900 dark:text-white">Registrera ärende</h3>
                <button wire:click="closeTriage" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <x-bootstrap-icon name="x-lg" class="size-5" />
                </button>
            </div>

            {{-- Form --}}
            <div class="space-y-4 px-6 py-5">

                @if($senderMember)
                <div class="flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                    <x-bootstrap-icon name="person-fill" class="size-4 shrink-0 text-zinc-400" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Kopplas till <span class="font-medium">{{ $senderMember['name'] }}</span>
                    </span>
                </div>
                @endif

                <div>
                    <label class="lbl">Typ</label>
                    <div class="mt-1 flex gap-2">
                        <button wire:click="$set('triageType', 'issue')"
                                class="flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors
                                       {{ $triageType === 'issue'
                                          ? 'border-complement bg-complement/10 text-complement dark:border-complement-light dark:text-complement-light'
                                          : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' }}">
                            Ärende
                        </button>
                        <button wire:click="$set('triageType', 'motion')"
                                class="flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors
                                       {{ $triageType === 'motion'
                                          ? 'border-complement bg-complement/10 text-complement dark:border-complement-light dark:text-complement-light'
                                          : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' }}">
                            Motion
                        </button>
                    </div>
                </div>

                <div>
                    <label class="lbl">Rubrik</label>
                    <input wire:model="triageTitle" type="text" class="inp w-full" />
                    @error('triageTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="lbl">Beskrivning <span class="font-normal text-zinc-400">(valfri)</span></label>
                    <textarea wire:model="triageDescription" rows="3" class="inp w-full resize-none"></textarea>
                </div>

            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-end gap-3 border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">
                <button wire:click="closeTriage" class="btn btn-ghost btn-sm">Avbryt</button>
                <button wire:click="saveTriage" wire:loading.attr="disabled" class="btn btn-primary btn-sm">
                    <span wire:loading.remove wire:target="saveTriage">Registrera</span>
                    <span wire:loading wire:target="saveTriage">Sparar…</span>
                </button>
            </div>

        </div>
    </div>
    @endif

</div>
