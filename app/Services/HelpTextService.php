<?php

namespace App\Services;

use App\Models\Content\HelpText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HelpTextService
{
    private ?Collection $cache = null;

    public function get(string $key, string $field = 'text'): string
    {
        if ($this->cache === null) {
            $this->cache = HelpText::all()->keyBy('key');
        }

        $helpText = $this->cache->get($key);

        if (! $helpText) {
            Log::warning("Help text missing: {$key}");

            return '';
        }

        if ($field === 'url') {
            return $helpText->displayUrl() ?? '';
        }

        return $helpText->displayText();
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
