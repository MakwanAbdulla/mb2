{{--
    Slide-in document viewer panel.

    Usage: drop <x-document-viewer /> once in a layout or page.
    Open from anywhere with:
      $dispatch('open-document', { url: '...', name: '...', mime: '...' })

    Or use the helper blade directive helper in templates:
      <button @click="$dispatch('open-document', {
                  url: '{{ route('document.inline', $doc) }}',
                  name: '{{ addslashes($doc->original_name) }}',
                  mime: '{{ $doc->mime_type }}'
              })">
          Visa
      </button>
--}}
<div
    x-data="{
        open: false,
        url: '',
        downloadUrl: '',
        name: '',
        mime: '',
        loading: false,

        show(url, name, mime, downloadUrl) {
            this.url         = url;
            this.downloadUrl = downloadUrl || url.replace(/(\?.*)?$/, '') + '/ladda-ner';
            this.name        = name;
            this.mime        = mime || 'application/pdf';
            this.loading     = true;
            this.open        = true;
        },
        close() {
            this.open = false;
            // Brief delay before clearing so the panel closes cleanly
            setTimeout(() => { this.url = ''; this.name = ''; this.mime = ''; }, 300);
        },
        get isPdf()   { return this.mime.includes('pdf'); },
        get isImage() { return this.mime.startsWith('image/'); },
    }"
    @open-document.window="show($event.detail.url, $event.detail.name, $event.detail.mime, $event.detail.downloadUrl)"
    @keydown.escape.window="open && close()"
    x-cloak
>
    {{-- Backdrop (subtle, click to close) --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-black/20 dark:bg-black/40 z-40"
        @click="close()"
        aria-hidden="true"
    ></div>

    {{-- Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-250"
        x-transition:enter-start="translate-x-full opacity-0"
        x-transition:enter-end="translate-x-0 opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-x-0 opacity-100"
        x-transition:leave-end="translate-x-full opacity-0"
        class="fixed inset-y-0 right-0 z-50 flex flex-col
               w-full sm:w-[600px] lg:w-[750px] xl:w-[900px]
               bg-white dark:bg-zinc-900
               shadow-2xl border-l border-zinc-200 dark:border-zinc-700"
        @click.stop
    >
        {{-- Header bar --}}
        <div class="flex items-center justify-between gap-3 px-4 py-3
                    border-b border-zinc-200 dark:border-zinc-700
                    bg-zinc-50 dark:bg-zinc-800 shrink-0">
            <div class="flex items-center gap-2 min-w-0">
                <x-bootstrap-icon
                    x-show="isPdf"
                    name="file-earmark-pdf-fill"
                    class="size-4 text-red-400 shrink-0" />
                <x-bootstrap-icon
                    x-show="isImage"
                    name="file-earmark-image-fill"
                    class="size-4 text-blue-400 shrink-0" />
                <span x-text="name"
                      class="text-sm font-medium text-zinc-700 dark:text-zinc-200 truncate"></span>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                {{-- Open in new tab --}}
                <a :href="url" target="_blank"
                   class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 p-1 rounded
                          hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                   title="Öppna i ny flik">
                    <x-bootstrap-icon name="box-arrow-up-right" class="size-4" />
                </a>
                <a :href="downloadUrl"
                   class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 p-1 rounded
                          hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                   title="Ladda ner">
                    <x-bootstrap-icon name="download" class="size-4" />
                </a>
                {{-- Close --}}
                <button @click="close()"
                        class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 p-1 rounded
                               hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                        title="Stäng (Esc)">
                    <x-bootstrap-icon name="x-lg" class="size-4" />
                </button>
            </div>
        </div>

        {{-- Content area --}}
        <div class="flex-1 overflow-hidden relative bg-zinc-100 dark:bg-zinc-950">

            {{-- Loading spinner (shown until iframe/img fires load event) --}}
            <div x-show="loading"
                 class="absolute inset-0 flex items-center justify-center z-10 bg-zinc-100 dark:bg-zinc-950">
                <svg class="size-8 animate-spin text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>

            {{-- PDF via iframe --}}
            <iframe
                x-show="isPdf && url"
                :src="isPdf && url ? url : ''"
                @load="loading = false"
                class="w-full h-full border-0"
                title="Dokumentvisare"
            ></iframe>

            {{-- Image --}}
            <div x-show="isImage && url"
                 class="w-full h-full overflow-auto flex items-start justify-center p-4">
                <img
                    :src="isImage && url ? url : ''"
                    @load="loading = false"
                    @@error="loading = false"
                    class="max-w-full rounded shadow-lg"
                    alt="Dokumentbild" />
            </div>

            {{-- Unsupported type fallback --}}
            <div x-show="url && !isPdf && !isImage"
                 x-init="loading = false"
                 class="flex flex-col items-center justify-center h-full gap-3 text-zinc-400">
                <x-bootstrap-icon name="file-earmark" class="size-12 opacity-40" />
                <p class="text-sm">Förhandsvisning ej tillgänglig för detta filformat.</p>
                <a :href="url" target="_blank"
                   class="btn btn-ghost btn-sm">
                    Öppna filen
                </a>
            </div>
        </div>
    </div>
</div>
