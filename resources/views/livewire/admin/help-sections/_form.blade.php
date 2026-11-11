<div class="space-y-4 px-5 py-5">

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="lbl">Rubrik <span class="text-red-500">*</span></label>
            <input type="text" wire:model.live="title"
                   class="inp mt-1 w-full" />
            @error('title') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="lbl">Slug (ankarlänk) <span class="text-red-500">*</span></label>
            <input type="text" wire:model="slug"
                   class="inp mt-1 w-full font-mono" />
            @error('slug') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="lbl">Grupp / mellanrubrik</label>
        <input type="text" wire:model="groupLabel" list="group-suggestions"
               placeholder="Lämna tomt för ingen rubrik"
               class="inp mt-1 w-full" />
        <datalist id="group-suggestions">
            @foreach ($existingGroups as $g)
                <option value="{{ $g }}">
            @endforeach
        </datalist>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Sektioner med samma grupprubrik visas under en gemensam mellanrubrik på hjälpsidan.</p>
        @error('groupLabel') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
    </div>

    <div>
        <div class="mb-1 flex items-center justify-between">
            <label class="lbl">Innehåll <span class="text-red-500">*</span></label>
            <button type="button" id="helpToggleHtmlBtn"
                    class="rounded border border-zinc-300 px-2 py-0.5 text-xs text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-white">
                HTML-läge
            </button>
        </div>

        <div wire:ignore>
            <div id="helpTrixWrapper" class="overflow-hidden rounded-lg border border-zinc-300 dark:border-zinc-600">
                <input type="hidden" id="help-content-input" value="{{ $content }}">
                <trix-editor input="help-content-input" class="trix-content"></trix-editor>
            </div>
            <textarea id="helpContentCode"
                      class="hidden w-full rounded-lg border border-zinc-300 bg-zinc-50 p-3 font-mono text-sm text-zinc-800 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200"
                      rows="14" spellcheck="false"></textarea>
        </div>

        @error('content') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-wrap gap-8">
        <div>
            <p class="lbl mb-2">Synlig för</p>
            <div class="space-y-2">
                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" wire:model="showPublic"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Oinloggade (publik)</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" wire:model="showAuth"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Alla inloggade</span>
                </label>
            </div>
        </div>
        <div class="flex-1 min-w-48">
            <label class="lbl">Synlig för behörighet</label>
            <select wire:model="requiredPermission" class="inp mt-1 w-full">
                <option value="">— Ingen behörighetsbegränsning —</option>
                @foreach ($permissions as $perm)
                    <option value="{{ $perm->name }}">{{ $perm->label ?? $perm->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Sektionen visas för användare med den valda behörigheten, oavsett ovanstående.</p>
            @error('requiredPermission') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="lbl">Sorteringsordning</label>
            <input type="number" wire:model="sortOrder" min="0" max="9999" step="10"
                   class="inp mt-1 w-28" />
            @error('sortOrder') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="flex gap-3 pt-1">
        <button wire:click="save" class="btn btn-primary">Spara</button>
        <button wire:click="cancelEdit" class="btn">Avbryt</button>
    </div>

</div>
