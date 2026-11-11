<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);
    }

    /**
     * Deliberately curated — never dump phpinfo()/getenv()/$_ENV/$_SERVER here.
     * Those include every value loaded from .env in cleartext (DB password,
     * API keys, service account credentials, …), readable by anyone this
     * page is ever shown to, now or after a future permission-check bug.
     */
    public function info(): array
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return [
            'PHP-version' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
            'OS' => PHP_OS_FAMILY,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time').'s',
            'max_input_vars' => ini_get('max_input_vars'),
            'date.timezone' => ini_get('date.timezone') ?: '(ej satt)',
            'OPcache' => $opcache ? ($opcache['opcache_enabled'] ? 'Aktivt' : 'Inaktivt') : 'Inte tillgängligt',
        ];
    }

    public function extensions(): array
    {
        $ext = get_loaded_extensions();
        sort($ext, SORT_FLAG_CASE | SORT_STRING);

        return $ext;
    }
}; ?>

<div class="space-y-6">
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">PHP-konfiguration</h2>
        </div>
        <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach($this->info() as $label => $value)
                <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="font-mono font-medium text-zinc-900 dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <details class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <summary class="cursor-pointer select-none px-5 py-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
            Laddade PHP-extensions ({{ count($this->extensions()) }})
        </summary>
        <div class="flex flex-wrap gap-1.5 border-t border-zinc-100 p-5 dark:border-zinc-800">
            @foreach($this->extensions() as $ext)
                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-mono text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $ext }}</span>
            @endforeach
        </div>
    </details>

    <p class="text-xs text-zinc-400 dark:text-zinc-500">
        Visar bara ofarlig diagnostikinformation — aldrig miljövariabler, <code>.env</code>-värden eller fullständig <code>phpinfo()</code>.
    </p>
</div>
