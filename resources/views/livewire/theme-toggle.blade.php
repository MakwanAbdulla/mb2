<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $theme = 'system';

    public function mount(): void
    {
        $this->theme = Auth::user()?->ui_theme ?? User::THEME_SYSTEM;
    }

    public function cycle(): void
    {
        $next = match($this->theme) {
            User::THEME_LIGHT  => User::THEME_DARK,
            User::THEME_DARK   => User::THEME_SYSTEM,
            default            => User::THEME_LIGHT,
        };
        $this->apply($next);
    }

    public function apply(string $theme): void
    {
        if (! in_array($theme, [User::THEME_LIGHT, User::THEME_DARK, User::THEME_SYSTEM])) {
            return;
        }
        Auth::user()->update(['ui_theme' => $theme]);
        $this->theme = $theme;
        $this->js(match($theme) {
            'dark'   => "localStorage.setItem('ui_theme','dark'); document.documentElement.classList.add('dark');",
            'light'  => "localStorage.setItem('ui_theme','light'); document.documentElement.classList.remove('dark');",
            default  => "localStorage.setItem('ui_theme','system'); document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);",
        });
    }
}; ?>

<button wire:click="cycle"
        title="{{ match($theme) { 'light' => 'Tema: Ljust – klicka för Mörkt', 'dark' => 'Tema: Mörkt – klicka för System', default => 'Tema: System – klicka för Ljust' } }}"
        class="flex size-9 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
    @if($theme === 'light')
        <x-bootstrap-icon name="sun-fill" class="size-4" />
    @elseif($theme === 'dark')
        <x-bootstrap-icon name="moon-stars-fill" class="size-4" />
    @else
        <x-bootstrap-icon name="circle-half" class="size-4" />
    @endif
</button>
