<?php

namespace App\Livewire\Admin\Navigation;

use Illuminate\Support\Facades\File;
use Livewire\Component;

class BootstrapIconPicker extends Component
{
    public string $search = '';

    public array $icons = [];

    public string $selected = '';

    public function mount(string $selected = ''): void
    {
        $this->selected = $selected;
        $this->loadIcons();
    }

    public function updatedSearch(): void
    {
        $this->loadIcons();
    }

    public function selectIcon(string $name): void
    {
        $this->selected = $name;
        $this->dispatch('iconSelected', $name);
    }

    public function loadIcons(): void
    {
        $iconPath = public_path('images/icons/bootstrap');

        if (! is_dir($iconPath)) {
            $this->icons = [];

            return;
        }

        $files = File::files($iconPath);
        $icons = [];

        foreach ($files as $file) {
            if ($file->getExtension() === 'svg') {
                $name = $file->getFilenameWithoutExtension();
                if (empty($this->search) || stripos($name, $this->search) !== false) {
                    $icons[] = $name;
                }
            }
        }

        sort($icons);
        $this->icons = array_slice($icons, 0, 200);
    }

    public function render()
    {
        return view('livewire.admin.navigation.bootstrap-icon-picker');
    }
}
