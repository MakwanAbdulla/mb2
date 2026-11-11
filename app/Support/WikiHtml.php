<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans wiki page HTML. Any active group member may edit a page, so unlike
 * CMS pages (trusted editors only) the content is never trusted: scripts,
 * event handlers, inline styles and javascript: links are dropped.
 */
final class WikiHtml
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return self::onlyTrixClasses(self::sanitizer()->sanitize($html));
    }

    /**
     * Trix styles images and galleries with its own classes (attachment,
     * attachment--preview, attachment__caption, attachment-gallery …). Any
     * other class is dropped: site classes like "fixed inset-0" would let a
     * page cover the screen.
     */
    private static function onlyTrixClasses(string $html): string
    {
        return preg_replace_callback('/\sclass="([^"]*)"/', function (array $m) {
            $kept = array_filter(
                preg_split('/\s+/', $m[1], -1, PREG_SPLIT_NO_EMPTY),
                fn (string $class) => preg_match('/^attachment(-gallery)?((--|__)[a-z0-9-]+)?$/', $class) === 1,
            );

            return $kept === [] ? '' : ' class="'.implode(' ', $kept).'"';
        }, $html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                // What Trix produces: text blocks, lists, links, quotes, code and images.
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowRelativeLinks()
                ->allowMediaSchemes(['http', 'https'])
                ->allowRelativeMedias()
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                // Only Trix's own classes survive (onlyTrixClasses).
                ->allowAttribute('class', ['figure', 'figcaption', 'div'])
                ->withMaxInputLength(500_000)
        );
    }
}
