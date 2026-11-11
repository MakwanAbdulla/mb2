<?php

use App\Models\AuditLog;
use App\Models\Content\NavigationItem;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component {

    public ?NavigationItem $item = null;

    #[Validate('required|string|max:255')]
    public string $label = '';

    #[Validate('nullable|string|max:255')]
    public ?string $icon = null;

    #[Validate('nullable|string|max:255')]
    public ?string $route_name = null;

    #[Validate('nullable|string|max:500')]
    public ?string $url = null;

    #[Validate('required|string|in:topnav,footer')]
    public string $location = 'topnav';

    #[Validate('required|integer|min:0')]
    public int $order = 0;

    #[Validate('nullable|exists:navigation_items,id')]
    public ?int $parent_id = null;

    #[Validate('nullable|string|max:255')]
    public ?string $permission = null;

    #[Validate('boolean')]
    public bool $requires_auth = false;

    #[Validate('boolean')]
    public bool $is_active = true;

    public function mount(?NavigationItem $item = null): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('cms.edit'), 403);

        $this->item = $item && $item->exists ? $item : null;

        if ($this->item) {
            $this->label        = $this->item->label;
            $this->icon         = $this->item->icon;
            $this->route_name   = $this->item->route_name;
            $this->url          = $this->item->url;
            $this->location     = $this->item->location;
            $this->order        = $this->item->order;
            $this->parent_id    = $this->item->parent_id;
            $this->permission   = $this->item->permission;
            $this->requires_auth= $this->item->requires_auth;
            $this->is_active    = $this->item->is_active;
        }
    }

    #[\Livewire\Attributes\On('iconSelected')]
    public function updateIcon(string $iconName): void
    {
        $this->icon = $iconName;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'label'         => $this->label,
            'icon'          => $this->icon ?: null,
            'route_name'    => $this->route_name ?: null,
            'url'           => $this->url ?: null,
            'location'      => $this->location,
            'order'         => $this->order,
            'parent_id'     => $this->parent_id,
            'permission'    => $this->permission ?: null,
            'requires_auth' => $this->requires_auth,
            'is_active'     => $this->is_active,
        ];

        if ($this->item) {
            $this->item->update($data);
            AuditLog::record('nav_item_updated', 'cms', $this->label, null);
            session()->flash('success', 'Menyalternativet uppdaterades.');
        } else {
            NavigationItem::create($data);
            AuditLog::record('nav_item_created', 'cms', $this->label, null);
            session()->flash('success', 'Menyalternativet skapades.');
        }

        $this->redirect(route('admin.navigation.index'), navigate: true);
    }

    public function with(): array
    {
        return [
            'availableParents' => NavigationItem::rootItems()
                ->where('location', $this->location)
                ->when($this->item, fn ($q) => $q->where('id', '!=', $this->item->id))
                ->get(),
        ];
    }
}; ?>

<x-slot:header>
    <x-page-header permission="cms.edit" :title="($item ? 'Redigera' : 'Nytt') . ' menyalternativ'" />
</x-slot:header>

<div>
    <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
        <form wire:submit="save" class="space-y-6">

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Label <span class="text-red-500">*</span></label>
                <input wire:model="label" type="text" placeholder="T.ex. Om oss" required
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                @error('label') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ikon</label>
                <input wire:model="icon" type="text" placeholder="house, person, calendar…"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                <div class="mt-2">
                    <livewire:admin.navigation.bootstrap-icon-picker :selected="$icon ?? ''" />
                </div>
                @error('icon') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Route-namn</label>
                <input wire:model="route_name" type="text" placeholder="dashboard, admin.pages.index…"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Använd antingen route-namn eller URL, inte båda.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL</label>
                <input wire:model="url" type="text" placeholder="/om-oss eller https://…"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Placering <span class="text-red-500">*</span></label>
                <select wire:model="location" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                    <option value="topnav">Toppmeny</option>
                    <option value="footer">Sidfot</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Förälder (undermeny)</label>
                <select wire:model="parent_id" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                    <option value="">Ingen (rotnivå)</option>
                    @foreach($availableParents as $parent)
                        @if(!$item || $parent->id !== $item->id)
                            <option value="{{ $parent->id }}">{{ $parent->label }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ordning</label>
                <input wire:model="order" type="number" min="0"
                       class="mt-1 w-32 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Behörighet</label>
                <select wire:model="permission" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                    <option value="">Alla</option>
                    <option value="admin">Admin</option>
                    <option value="website-editor">Webbansvarig</option>
                    <option value="member-manager">Medlemsansvarig</option>
                </select>
            </div>

            <div class="space-y-3">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" wire:model="requires_auth" class="h-4 w-4 rounded border-zinc-300 accent-brand-dark" />
                    Kräver inloggning
                </label>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" wire:model="is_active" class="h-4 w-4 rounded border-zinc-300 accent-brand-dark" />
                    Aktiv
                </label>
            </div>

            <div class="flex gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <button type="submit"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                    {{ $item ? 'Uppdatera' : 'Skapa' }}
                </button>
                <a href="{{ route('admin.navigation.index') }}" wire:navigate
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    Avbryt
                </a>
            </div>
        </form>
    </div>
</div>
