<?php

use App\Services\HelpTextService;
use App\Services\WidgetService;

if (! function_exists('linkify')) {
    /**
     * Escapes HTML and converts URLs and email addresses to clickable links.
     * Safe to render with {!! !!} — HTML is escaped before link injection.
     */
    function linkify(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $escaped = e($text);

        return preg_replace_callback(
            '~(https?://[^\s<>"\']+)|([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})~i',
            function (array $m): string {
                if (! empty($m[1])) {
                    $url = rtrim($m[1], '.,;:!?)>]');
                    return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" '
                        . 'class="underline hover:opacity-80">' . $url . '</a>';
                }
                return '<a href="mailto:' . $m[2] . '" '
                    . 'class="underline hover:opacity-80">' . $m[2] . '</a>';
            },
            $escaped
        );
    }
}

if (! function_exists('help_text')) {
    function help_text(string $key, string $field = 'text'): string
    {
        return app(HelpTextService::class)->get($key, $field);
    }
}

/**
 * Format an amount stored in öre (integer) as a Swedish SEK string.
 * e.g. 125000 → "1 250,00 kr"
 */
if (! function_exists('format_ore')) {
    function format_ore(int $ore, bool $withCurrency = true): string
    {
        $kr       = number_format($ore / 100, 2, ',', ' ');
        return $withCurrency ? "{$kr} kr" : $kr;
    }
}

if (! function_exists('cms_content')) {
    /**
     * Render CMS page content, replacing widget tags with dynamic components.
     * Widgets are registered in AppServiceProvider via WidgetService.
     */
    function cms_content(?string $html): string
    {
        return app(WidgetService::class)->render($html);
    }

    /**
     * Format a tournament score/points value with Swedish decimal comma.
     * Shows decimals only when the value is not a whole number.
     * E.g.: 5 → "5", 5.5 → "5,5", 12.25 → "12,25"
     */
    function tournament_num(int|float|null $value): string
    {
        if ($value === null) {
            return '–';
        }
        $float = (float) $value;
        if ($float == (int) $float) {
            return (string) (int) $float;
        }
        // Remove trailing zeros: 5.50 → "5,5", 5.25 → "5,25"
        return rtrim(rtrim(number_format($float, 2, ',', ''), '0'), ',');
    }
}
