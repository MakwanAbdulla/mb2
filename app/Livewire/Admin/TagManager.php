<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class TagManager extends Component
{
    public string $newTagName  = '';
    public string $renamingId  = '';
    public string $renameValue = '';
    public string $mergingId   = '';
    public string $mergeTarget = '';
    public bool   $saved       = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.categories'), 403);
    }

    public function addTag(): void
    {
        $this->validate(['newTagName' => 'required|string|max:100|unique:tags,name'], [
            'newTagName.required' => 'Ange ett taggnamn.',
            'newTagName.max'      => 'Taggnamnet får vara högst 100 tecken.',
            'newTagName.unique'   => 'Det finns redan en tagg med det namnet.',
        ]);
        $name = mb_strtolower(trim($this->newTagName));
        Tag::findOrCreateByName($name);
        AuditLog::record('tag_created', 'tags', $name);
        $this->newTagName = '';
        $this->saved = true;
    }

    public function startRename(int $id, string $name): void
    {
        $this->renamingId  = (string) $id;
        $this->renameValue = $name;
        $this->mergingId   = '';
    }

    public function saveRename(): void
    {
        $this->validate(['renameValue' => 'required|string|max:100'], [
            'renameValue.required' => 'Ange ett nytt namn.',
            'renameValue.max'      => 'Taggnamnet får vara högst 100 tecken.',
        ]);
        $name = mb_strtolower(trim($this->renameValue));
        $tag  = Tag::findOrFail((int) $this->renamingId);
        $old  = $tag->name;
        $tag->update([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
        AuditLog::record('tag_renamed', 'tags', $name, null, ['from' => $old, 'to' => $name]);
        $this->renamingId  = '';
        $this->renameValue = '';
        $this->saved = true;
    }

    public function cancelRename(): void
    {
        $this->renamingId  = '';
        $this->renameValue = '';
    }

    public function startMerge(int $id): void
    {
        $this->mergingId   = (string) $id;
        $this->mergeTarget = '';
        $this->renamingId  = '';
    }

    public function saveMerge(): void
    {
        $this->validate(['mergeTarget' => 'required|string|max:100'], [
            'mergeTarget.required' => 'Ange vilken tagg den ska slås ihop med.',
            'mergeTarget.max'      => 'Taggnamnet får vara högst 100 tecken.',
        ]);

        $source = Tag::findOrFail((int) $this->mergingId);
        $target = Tag::findOrCreateByName(mb_strtolower(trim($this->mergeTarget)));

        // Re-attach all taggables from source to target (skipping duplicates)
        foreach ($source->bookings as $booking) {
            if (! $booking->tags()->where('tags.id', $target->id)->exists()) {
                $booking->tags()->attach($target->id);
            }
        }
        foreach ($source->sections as $section) {
            if (! $section->tags()->where('tags.id', $target->id)->exists()) {
                $section->tags()->attach($target->id);
            }
        }

        $sourceName = $source->name;
        $source->delete();

        AuditLog::record('tag_merged', 'tags', "{$sourceName} → {$target->name}", null, ['from' => $sourceName, 'to' => $target->name]);

        $this->mergingId   = '';
        $this->mergeTarget = '';
        $this->saved = true;
    }

    public function cancelMerge(): void
    {
        $this->mergingId   = '';
        $this->mergeTarget = '';
    }

    public function deleteTag(int $id): void
    {
        $tag = Tag::findOrFail($id);
        $name = $tag->name;
        $tag->delete();
        AuditLog::record('tag_deleted', 'tags', $name);
        $this->saved = true;
    }

    public function render()
    {
        $tags     = Tag::withCount(['bookings', 'sections'])->orderBy('name')->get();
        $tagNames = $tags->pluck('name')->toArray();

        return view('livewire.admin.tag-manager', compact('tags', 'tagNames'));
    }
}
