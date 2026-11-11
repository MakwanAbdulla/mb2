<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Content\Notice;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NoticeManager extends Component
{
    public string $title     = '';
    public string $message   = '';
    public string $startsAt  = '';
    public string $endsAt    = '';
    public int    $duration  = 10;
    public string $type      = 'info';
    public bool   $isPublic  = false;
    public ?int   $editingId = null;

    public string $tab = 'active';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('notices.manage'), 403);

        $this->startsAt = today()->toDateString();
        $this->endsAt   = today()->toDateString();
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('notices.manage'), 403);

        $this->validate([
            'title'    => ['nullable', 'string', 'max:100'],
            'message'  => ['required', 'string', 'max:500'],
            'startsAt' => ['required', 'date'],
            'endsAt'   => ['required', 'date', 'gte:startsAt'],
            'duration' => ['required', 'integer', 'min:3', 'max:60'],
            'type'     => ['required', 'in:info,success,warning,error'],
            'isPublic' => ['boolean'],
        ], [], [
            'title'    => 'rubrik',
            'message'  => 'meddelande',
            'startsAt' => 'startdatum',
            'endsAt'   => 'slutdatum',
            'duration' => 'visningstid',
        ]);

        $subject = ($this->title ?: mb_substr($this->message, 0, 60)) . " ({$this->startsAt}–{$this->endsAt})";

        if ($this->editingId) {
            $notice = Notice::findOrFail($this->editingId);
            $notice->fill([
                'title'     => $this->title ?: null,
                'message'   => $this->message,
                'starts_at' => $this->startsAt,
                'ends_at'   => $this->endsAt,
                'duration'  => $this->duration,
                'type'      => $this->type,
                'is_public' => $this->isPublic,
            ]);
            $changes = [];
            foreach ($notice->getDirty() as $key => $new) {
                $changes[$key] = ['from' => (string) $notice->getOriginal($key), 'to' => (string) $new];
            }
            $notice->save();
            AuditLog::record('notice_updated', 'notices', $subject, $notice, $changes ?: null);
        } else {
            $notice = Notice::create([
                'user_id'   => Auth::id(),
                'title'     => $this->title ?: null,
                'message'   => $this->message,
                'starts_at' => $this->startsAt,
                'ends_at'   => $this->endsAt,
                'duration'  => $this->duration,
                'type'      => $this->type,
                'is_public' => $this->isPublic,
            ]);
            AuditLog::record('notice_created', 'notices', $subject, $notice, [
                'type'      => $this->type,
                'is_public' => $this->isPublic,
                'starts_at' => $this->startsAt,
                'ends_at'   => $this->endsAt,
                'duration'  => $this->duration,
            ]);
        }

        $this->cancelEdit();
    }

    public function edit(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('notices.manage'), 403);

        $notice = Notice::findOrFail($id);

        $this->editingId = $id;
        $this->title     = $notice->title ?? '';
        $this->message   = $notice->message;
        $this->startsAt  = $notice->starts_at->toDateString();
        $this->endsAt    = $notice->ends_at->toDateString();
        $this->duration  = $notice->duration;
        $this->type      = $notice->type;
        $this->isPublic  = $notice->is_public;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->reset('title', 'message');
        $this->startsAt = today()->toDateString();
        $this->endsAt   = today()->toDateString();
        $this->duration = 10;
        $this->type     = 'info';
        $this->isPublic = false;
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('notices.manage'), 403);

        $notice  = Notice::findOrFail($id);
        $subject = ($notice->title ?? mb_substr($notice->message, 0, 60)) . " ({$notice->starts_at->toDateString()}–{$notice->ends_at->toDateString()})";
        $notice->delete();
        AuditLog::record('notice_deleted', 'notices', $subject);
    }

    public function render()
    {
        $active = Notice::where('ends_at', '>=', today())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $past = Notice::where('ends_at', '<', today())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        return view('livewire.admin.notice-manager', compact('active', 'past'));
    }
}
