<?php

use App\Models\Content\Page;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');

new class extends Component {
    public Page $page;

    public function mount(string $path): void
    {
        $segments = explode('/', trim($path, '/'));

        if (count($segments) === 1) {
            $this->page = Page::published()->whereNull('path_prefix')->where('slug', $segments[0])->firstOrFail();
        } else {
            $this->page = Page::published()->where('path_prefix', $segments[0])->where('slug', $segments[1])->firstOrFail();
        }

        title($this->page->title);
    }
};

?>

<x-slot:header>
    <x-page-header :title="$page->title" :subtitle="$page->subtitle" :editUrl="route('admin.pages.edit', $page)" />
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
    <div class="prose prose-zinc max-w-none dark:prose-invert">
        {!! cms_content($page->content) !!}
    </div>
</div>
