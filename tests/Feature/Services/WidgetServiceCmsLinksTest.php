<?php

use App\Models\Content\NavigationItem;
use App\Models\Content\Page;
use App\Services\WidgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function widgetService(): WidgetService
{
    return app(WidgetService::class);
}

function makePage(array $attrs = []): Page
{
    return Page::create(array_merge([
        'title' => 'Testsida',
        'slug' => 'testsida-'.uniqid(),
        'content' => '<p>Testinnehåll</p>',
        'is_published' => true,
    ], $attrs));
}

function makeNavItem(array $attrs = []): NavigationItem
{
    return NavigationItem::create(array_merge([
        'label' => 'Testlänk',
        'location' => NavigationItem::LOCATION_TOPNAV,
        'order' => 0,
        'is_active' => true,
    ], $attrs));
}

// ── [page] ─────────────────────────────────────────────────────────────────

test('page widget renders a link to the page', function () {
    $page = makePage(['title' => 'Om föreningen', 'slug' => 'om-foreningen']);

    $html = widgetService()->render("[page id=\"{$page->id}\"]");

    expect($html)->toContain($page->url())
        ->and($html)->toContain('Om föreningen');
});

test('page widget uses custom text when given', function () {
    $page = makePage(['title' => 'Om föreningen', 'slug' => 'om-foreningen']);

    $html = widgetService()->render("[page id=\"{$page->id}\" text=\"Läs mer här\"]");

    expect($html)->toContain('Läs mer här')
        ->and($html)->not->toContain('Om föreningen');
});

test('page widget renders box style with subtitle', function () {
    $page = makePage(['title' => 'Stadgar', 'subtitle' => 'Senast uppdaterad 2026', 'slug' => 'stadgar']);

    $html = widgetService()->render("[page id=\"{$page->id}\" box=\"true\"]");

    expect($html)->toContain('Senast uppdaterad 2026')
        ->and($html)->toContain('rounded-lg border');
});

test('page widget shows fallback for missing page', function () {
    $html = widgetService()->render('[page id="999999"]');

    expect($html)->toContain('Sida hittades inte');
});

// ── [menu] ─────────────────────────────────────────────────────────────────

test('menu widget renders a link using resolvedUrl', function () {
    $item = makeNavItem(['label' => 'Kontakt', 'url' => '/kontakt']);

    $html = widgetService()->render("[menu id=\"{$item->id}\"]");

    expect($html)->toContain($item->resolvedUrl())
        ->and($html)->toContain('Kontakt');
});

test('menu widget shows fallback when item has no resolvable url', function () {
    $item = makeNavItem(['label' => 'Tom länk', 'url' => null, 'route_name' => null]);

    $html = widgetService()->render("[menu id=\"{$item->id}\"]");

    expect($html)->toContain('Menyalternativ hittades inte');
});

// ── [route] ────────────────────────────────────────────────────────────────

test('route widget renders a link to a named route', function () {
    $html = widgetService()->render('[route name="login" text="Logga in"]');

    expect($html)->toContain(route('login'))
        ->and($html)->toContain('Logga in');
});

test('route widget shows fallback for unknown route', function () {
    $html = widgetService()->render('[route name="does-not-exist"]');

    expect($html)->toContain('Route hittades inte');
});

// ── [url] ──────────────────────────────────────────────────────────────────

test('url widget renders a link to an external url', function () {
    $html = widgetService()->render('[url url="https://example.com" text="Besök webbplatsen"]');

    expect($html)->toContain('https://example.com')
        ->and($html)->toContain('Besök webbplatsen');
});

test('url widget shows fallback when no url given', function () {
    $html = widgetService()->render('[url]');

    expect($html)->toContain('Ingen url angiven');
});

// ── Reference panel ───────────────────────────────────────────────────────

test('all four cms link widgets are registered', function () {
    $tags = collect(widgetService()->all())->pluck('tag');

    expect($tags)->toContain('page', 'menu', 'route', 'url');
});
