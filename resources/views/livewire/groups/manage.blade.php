<?php

use App\Models\Organization\Section;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout};

layout('components.layouts.public');

new class extends Component {
    public function mount(Section $section): void
    {
        $this->redirect(route('groups.show', $section) . '#members', navigate: true);
    }
}; ?>

<div></div>
