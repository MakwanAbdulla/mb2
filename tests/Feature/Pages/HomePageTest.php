<?php

use App\Models\Content\Page;
use App\Services\WidgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

it('renders registered widgets in the home page content', function () {
    View::addNamespace('test', __DIR__.'/fixtures');
    app(WidgetService::class)->register('test-widget', 'test::test-widget', 'Test', 'Testwidget');

    Page::updateOrCreate(['slug' => 'hem'], [
        'title' => 'Hem',
        'content' => '<p>Före</p>[test-widget label="hej"]<p>Efter</p>',
        'is_published' => true,
        'published_at' => now()->subDay(),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Testwidget hej', false)
        ->assertDontSee('[test-widget', false);
});
