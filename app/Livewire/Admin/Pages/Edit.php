<?php

namespace App\Livewire\Admin\Pages;

use App\Models\AuditLog;
use App\Models\Content\NavigationItem;
use App\Models\Content\Page;
use App\Services\WidgetService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Edit extends Component
{
    public Page $page;

    public string $title = '';
    public string $subtitle = '';
    public string $slug = '';
    public ?string $path_prefix = null;
    public string $content = '';
    public bool $is_published = false;
    public bool $show_in_nav = false;
    public string $nav_title = '';
    public int $sort_order = 0;
    public ?string $published_at = null;
    public ?string $unpublished_at = null;
    public array $unknownWidgetsWarning = [];

    public function mount(Page $page): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('cms.edit'), 403);

        $this->page = $page;
        $this->title        = $page->title_draft ?? $page->title;
        $this->subtitle     = $page->subtitle_draft ?? $page->subtitle ?? '';
        $this->content      = $page->content_draft ?? $page->content ?? '';
        $this->slug         = $page->slug;
        $this->path_prefix  = $page->path_prefix;
        $this->is_published = $page->is_published;
        $this->show_in_nav  = $page->show_in_nav;
        $this->nav_title    = $page->nav_title ?? '';
        $this->sort_order   = $page->sort_order;
        $this->published_at   = $page->published_at?->format('Y-m-d\TH:i');
        $this->unpublished_at = $page->unpublished_at?->format('Y-m-d\TH:i');
    }

    public function saveAndPublish(): void
    {
        $this->save();
        $this->page->publishDraft();

        AuditLog::record('page_published', 'pages', $this->page->title, $this->page);

        session()->flash('success', 'Ändringarna har publicerats.');
        $this->redirect(route('admin.pages.edit', $this->page), navigate: true);
    }

    public function discardDraft(): void
    {
        if (! $this->page->has_draft) {
            return;
        }

        $this->page->discardDraft();
        AuditLog::record('page_draft_discarded', 'pages', $this->page->title, $this->page);

        session()->flash('success', 'Ändringarna har kasserats.');
        $this->redirect(route('admin.pages.edit', $this->page), navigate: true);
    }

    public function update(): void
    {
        $this->save();
        AuditLog::record('page_updated', 'pages', $this->page->title, $this->page);

        session()->flash('success', 'Utkastet har sparats.');
        $this->redirect(route('admin.pages.edit', $this->page), navigate: true);
    }

    private function save(): void
    {
        $this->validate([
            'title'         => 'required|string|max:255',
            'subtitle'      => 'nullable|string|max:500',
            'slug'          => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                                Rule::unique('pages')->where(fn ($q) => $q->where('path_prefix', $this->path_prefix))->ignore($this->page->id)],
            'path_prefix'   => 'nullable|string|max:100|regex:/^[a-z0-9-]+$/',
            'content'       => 'required|string',
            'is_published'  => 'boolean',
            'show_in_nav'   => 'boolean',
            'nav_title'     => 'nullable|string|max:255',
            'sort_order'    => 'required|integer|min:0',
            'published_at'  => 'nullable|date',
            'unpublished_at'=> 'nullable|date|after:published_at',
        ], [
            'title.required'    => 'Titel är obligatorisk.',
            'slug.required'     => 'Slug är obligatorisk.',
            'slug.unique'       => 'Denna slug används redan av en annan sida.',
            'content.required'  => 'Innehåll är obligatoriskt.',
            'unpublished_at.after' => 'Avpubliceringstid måste vara efter publiceringstid.',
        ]);

        // Soft warning: check for unrecognised widget tags
        preg_match_all('/\[([a-z0-9-]+)(?:\s[^\]]*)?\]/', $this->content, $tagMatches);
        $usedTags   = array_unique($tagMatches[1] ?? []);
        $knownTags  = array_column(app(WidgetService::class)->all(), 'tag');
        $this->unknownWidgetsWarning = array_values(array_diff($usedTags, $knownTags));

        $this->page->update([
            'title_draft'   => $this->title,
            'subtitle_draft'=> $this->subtitle ?: null,
            'content_draft' => $this->content,
            'has_draft'     => true,
            'slug'          => $this->slug,
            'path_prefix'   => $this->path_prefix ?: null,
            'is_published'  => $this->is_published,
            'show_in_nav'   => $this->show_in_nav,
            'nav_title'     => $this->nav_title ?: null,
            'sort_order'    => $this->sort_order,
            'published_at'  => $this->published_at,
            'unpublished_at'=> $this->unpublished_at,
        ]);
    }

    public function render()
    {
        return view('livewire.admin.pages.edit', [
            'page'           => $this->page,
            'navGroups'      => NavigationItem::active()->topnav()->rootItems()
                ->whereNull('url')->whereNull('route_name')->pluck('label', 'label'),
        ])->layout('components.layouts.public');
    }
}
