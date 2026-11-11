<?php

use App\Models\Content\Page;
use Illuminate\View\View;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;

layout('components.layouts.public');

new class extends Component {
    public ?Page $page = null;

    public function mount(): void
    {
        $this->page = Page::published()->where('slug', 'hem')->first();
    }

    public function rendering(View $view): void
    {
        if ($this->page) {
            $view->title($this->page->title);
        }
    }

    public function heroTitle(): string
    {
        return $this->page?->title ?? config('app.name');
    }

    public function heroSubtitle(): string
    {
        return $this->page?->subtitle ?? 'Borås spelförening för brädspel, figurspel, kortspel och rollspel';
    }
};

?>

<x-slot:metaDescription>{{ $this->heroSubtitle() }}</x-slot:metaDescription>

<x-slot:header>
    <x-home.hero :title="$this->heroTitle()" :subtitle="$this->heroSubtitle()" :editUrl="$page ? route('admin.pages.edit', $page) : null" />
</x-slot:header>

<div>
    @if($page)
        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
            <div class="prose prose-zinc max-w-none dark:prose-invert">
                {!! cms_content($page->content) !!}
            </div>
        </div>
    @endif
</div>
