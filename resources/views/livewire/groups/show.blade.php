<?php

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Comment;
use App\Models\Events\Event;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingTask;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\Section;
use App\Models\Submission;
use App\Models\Tournament\Tournament;
use App\Models\WorkspaceSharedDrive;
use App\Models\Reaction;
use App\Models\SectionPost;
use App\Models\Tag;
use App\Notifications\SectionPostCommentNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => $this->section->name);

new class extends Component {

    public Section $section;
    public string  $tab = 'posts';

    // Post form
    public string $postBody      = '';
    public string $postTagsInput = '';

    // Comment forms + open state
    public array $commentBody   = [];
    public array $openComments  = [];

    public ?string $flash = null;

    public function mount(Section $section): void
    {
        abort_unless($section->canView(Auth::user()?->member), 403);
        $this->section = $section;

        $validTabs = ['posts', 'members', 'events', 'meetings', 'tournaments', 'bookings', 'submissions', 'subgroups', 'about'];
        if ($t = request()->query('tab')) {
            if (in_array($t, $validTabs, true)) {
                $this->tab = $t;
            }
        }
    }

    #[Computed]
    public function isMember(): bool
    {
        $member = Auth::user()?->member;
        if (! $member) return false;
        return $this->section->activeMembers()->where('member_id', $member->id)->exists();
    }

    #[Computed]
    public function isGroupAdmin(): bool
    {
        $member = Auth::user()?->member;
        if (! $member) return false;
        return $this->section->sectionRoles()
            ->where('member_id', $member->id)
            ->where('is_admin', true)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->exists();
    }

    #[Computed]
    public function canPost(): bool
    {
        if (! Auth::check()) return false;
        return $this->isMember || Auth::user()->hasAnyPermission(['sections.manage']);
    }

    #[Computed]
    public function canViewMeetings(): bool
    {
        return Auth::user()?->hasAnyPermission(['meetings.view', 'meetings.manage']) ?? false;
    }

    // ── Posts ─────────────────────────────────────────────────────────────────

    #[Computed]
    public function posts()
    {
        return SectionPost::where('section_id', $this->section->id)
            ->with(['author', 'tags', 'reactions', 'comments.author', 'comments.reactions'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();
    }

    public function createPost(): void
    {
        abort_unless($this->canPost, 403);
        $this->validate(['postBody' => 'required|min:1|max:10000']);

        $post = SectionPost::create([
            'section_id' => $this->section->id,
            'author_id'  => Auth::id(),
            'body'       => trim($this->postBody),
        ]);

        $tagIds = [];
        foreach (array_filter(array_map('trim', explode(',', $this->postTagsInput))) as $name) {
            if ($name !== '') $tagIds[] = Tag::findOrCreateByName($name)->id;
        }
        $post->tags()->sync($tagIds);

        $this->postBody = $this->postTagsInput = '';
        unset($this->posts);
    }

    public function deletePost(int $postId): void
    {
        $post = SectionPost::where('section_id', $this->section->id)->findOrFail($postId);
        abort_unless(Auth::id() === $post->author_id || $this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $post->delete();
        unset($this->posts);
    }

    public function togglePin(int $postId): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $post = SectionPost::where('section_id', $this->section->id)->findOrFail($postId);
        $post->update(['is_pinned' => ! $post->is_pinned]);
        unset($this->posts);
    }

    public function togglePostReaction(int $postId, string $emoji): void
    {
        abort_unless(Auth::check(), 403);
        $post = SectionPost::where('section_id', $this->section->id)->findOrFail($postId);
        $post->load('reactions');
        $post->toggleReaction($emoji);
        unset($this->posts);
    }

    public function toggleCommentReaction(int $commentId, string $emoji): void
    {
        abort_unless(Auth::check(), 403);
        $comment = Comment::findOrFail($commentId);
        $comment->load('reactions');
        $comment->toggleReaction($emoji);
        unset($this->posts);
    }

    public function toggleComments(int $postId): void
    {
        if (in_array($postId, $this->openComments)) {
            $this->openComments = array_values(array_diff($this->openComments, [$postId]));
        } else {
            $this->openComments[] = $postId;
        }
    }

    public function addComment(int $postId): void
    {
        abort_unless($this->canPost, 403);
        $body = trim($this->commentBody[$postId] ?? '');
        if (! $body) return;

        $post = SectionPost::with('author.user', 'section')
            ->where('section_id', $this->section->id)
            ->findOrFail($postId);

        $comment = $post->comments()->create(['author_id' => Auth::id(), 'body' => $body]);

        if ($post->author && $post->author_id !== Auth::id()) {
            $post->author->notify(new SectionPostCommentNotification($post, $comment->load('author')));
        }

        $notified = [$post->author_id, Auth::id()];
        $post->comments()
            ->where('author_id', '!=', Auth::id())
            ->whereNotIn('author_id', $notified)
            ->with('author')
            ->get()
            ->unique('author_id')
            ->each(function ($c) use ($post, $comment, &$notified) {
                if (! in_array($c->author_id, $notified) && $c->author) {
                    $c->author->notify(new SectionPostCommentNotification($post, $comment));
                    $notified[] = $c->author_id;
                }
            });

        $this->commentBody[$postId] = '';
        if (! in_array($postId, $this->openComments)) $this->openComments[] = $postId;
        unset($this->posts);
    }

    public function deleteComment(int $commentId): void
    {
        $comment = Comment::findOrFail($commentId);
        abort_unless(Auth::id() === $comment->author_id || $this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $comment->delete();
        unset($this->posts);
    }

    // ── Members ───────────────────────────────────────────────────────────────

    public string  $memberSearch   = '';
    public ?int    $editRoleId     = null;
    public int     $roleMemberId   = 0;
    public string  $roleTitle      = '';
    public bool    $roleIsAdmin    = false;
    public string  $roleValidFrom  = '';
    public string  $roleValidUntil = '';

    #[Computed]
    public function members()
    {
        return $this->section->activeMembers()
            ->with(['sectionRoles' => fn ($q) => $q->where('section_id', $this->section->id)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))])
            ->orderBy('first_name')->orderBy('last_name')
            ->get();
    }

    #[Computed]
    public function pendingMembers()
    {
        return $this->section->pendingMembers()->orderBy('first_name')->orderBy('last_name')->get();
    }

    #[Computed]
    public function memberSearchResults()
    {
        if (strlen($this->memberSearch) < 2) return collect();

        $existingIds = $this->section->sectionMemberships()
            ->whereNull('left_at')->pluck('member_id');

        return \App\Models\Membership\Member::where(function ($q) {
            $q->where('first_name', 'like', '%' . $this->memberSearch . '%')
              ->orWhere('last_name',  'like', '%' . $this->memberSearch . '%')
              ->orWhere('email',      'like', '%' . $this->memberSearch . '%');
        })->whereNotIn('id', $existingIds)->limit(8)->get();
    }

    public function addMemberToGroup(int $memberId): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        \App\Models\Organization\SectionMembership::updateOrCreate(
            ['section_id' => $this->section->id, 'member_id' => $memberId],
            ['status' => 'active', 'joined_at' => now(), 'left_at' => null]
        );
        $this->memberSearch = '';
        unset($this->members, $this->memberSearchResults);
    }

    public function approveMember(int $memberId): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $this->section->sectionMemberships()
            ->where('member_id', $memberId)->whereNull('left_at')
            ->update(['status' => 'active', 'joined_at' => now()]);
        unset($this->members, $this->pendingMembers);
    }

    public function rejectMember(int $memberId): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $this->section->sectionMemberships()
            ->where('member_id', $memberId)->whereNull('left_at')
            ->update(['left_at' => now()]);
        unset($this->pendingMembers);
    }

    public function removeMemberFromGroup(int $memberId): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $this->section->sectionMemberships()
            ->where('member_id', $memberId)->whereNull('left_at')
            ->update(['left_at' => now()]);
        unset($this->members);
    }

    public function startNewRole(int $memberId): void
    {
        $this->editRoleId = null;
        $this->roleMemberId = $memberId;
        $this->roleTitle = '';
        $this->roleIsAdmin = false;
        $this->roleValidFrom = now()->format('Y-m-d');
        $this->roleValidUntil = '';
    }

    public function editRole(int $roleId): void
    {
        $role = \App\Models\Organization\SectionRole::findOrFail($roleId);
        $this->editRoleId = $role->id;
        $this->roleMemberId = $role->member_id;
        $this->roleTitle = $role->title;
        $this->roleIsAdmin = $role->is_admin;
        $this->roleValidFrom = $role->valid_from->format('Y-m-d');
        $this->roleValidUntil = $role->valid_until?->format('Y-m-d') ?? '';
    }

    public function saveRole(): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $this->validate([
            'roleMemberId'   => 'required|exists:members,id',
            'roleTitle'      => 'required|string|max:120',
            'roleValidFrom'  => 'required|date',
            'roleValidUntil' => 'nullable|date|after_or_equal:roleValidFrom',
        ]);

        $data = [
            'section_id'  => $this->section->id,
            'member_id'   => $this->roleMemberId,
            'title'       => $this->roleTitle,
            'is_admin'    => $this->roleIsAdmin,
            'valid_from'  => $this->roleValidFrom,
            'valid_until' => $this->roleValidUntil ?: null,
        ];

        if ($this->editRoleId) {
            \App\Models\Organization\SectionRole::findOrFail($this->editRoleId)->update($data);
        } else {
            \App\Models\Organization\SectionRole::create($data);
        }

        $this->editRoleId = $this->roleMemberId = 0;
        $this->roleTitle = $this->roleValidFrom = $this->roleValidUntil = '';
        $this->roleIsAdmin = false;
        $this->resetValidation();
        unset($this->members);
        $this->flash = 'Roll sparad.';
    }

    public function removeRole(int $roleId): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $role = \App\Models\Organization\SectionRole::findOrFail($roleId);
        if ($role->is_admin) {
            $adminCount = \App\Models\Organization\SectionRole::where('section_id', $this->section->id)
                ->where('is_admin', true)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
                ->count();
            if ($adminCount <= 1) {
                $this->addError('removeRole', 'Det måste finnas minst en admin i gruppen.');
                return;
            }
        }
        $role->delete();
        unset($this->members);
        $this->flash = 'Roll borttagen.';
    }

    public function cancelRoleEdit(): void
    {
        $this->editRoleId = $this->roleMemberId = 0;
        $this->roleTitle = $this->roleValidFrom = $this->roleValidUntil = '';
        $this->roleIsAdmin = false;
        $this->resetValidation();
    }

    // ── Events ────────────────────────────────────────────────────────────────

    #[Computed]
    public function events()
    {
        return Event::where('organizer_section_id', $this->section->id)
            ->orWhere('access_section_id', $this->section->id)
            ->whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED])
            ->with('occurrences')
            ->orderByDesc('created_at')
            ->get();
    }

    // ── Meetings ──────────────────────────────────────────────────────────────

    #[Computed]
    public function meetings()
    {
        return Meeting::where('section_id', $this->section->id)
            ->whereIn('status', ['notice_sent', 'ongoing', 'pending_approval', 'approved', 'published'])
            ->orderByDesc('scheduled_at')
            ->get();
    }

    // ── Edit group ───────────────────────────────────────────────────────────

    public bool   $editingAbout   = false;
    public string $editName       = '';
    public string $editDesc       = '';
    public string $editVisibility = '';
    public string $editMembership = '';
    public string $editTagsInput  = '';

    public function startEditAbout(): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $this->editName       = $this->section->name;
        $this->editDesc       = $this->section->description ?? '';
        $this->editVisibility = $this->section->visibility;
        $this->editMembership = $this->section->membership_type;
        $this->editTagsInput  = $this->section->tags->pluck('name')->join(', ');
        $this->editingAbout   = true;
    }

    public function saveAbout(): void
    {
        abort_unless($this->isGroupAdmin || Auth::user()->hasPermissionTo('sections.manage'), 403);
        $this->validate([
            'editName'       => 'required|string|min:2|max:120',
            'editDesc'       => 'nullable|string|max:500',
            'editVisibility' => 'required|in:public,members,private,secret',
            'editMembership' => 'required|in:open,application,closed',
        ]);

        $this->section->update([
            'name'            => $this->editName,
            'description'     => $this->editDesc ?: null,
            'visibility'      => $this->editVisibility,
            'membership_type' => $this->editMembership,
        ]);

        $tagIds = [];
        foreach (array_filter(array_map('trim', explode(',', $this->editTagsInput))) as $name) {
            if ($name !== '') $tagIds[] = Tag::findOrCreateByName($name)->id;
        }
        $this->section->tags()->sync($tagIds);

        $this->section->refresh()->load('tags');
        $this->editingAbout = false;
        $this->flash = 'Gruppprofilen uppdaterades.';
        AuditLog::record('section_updated', 'sections', $this->section->name, $this->section);
    }

    // ── Bookings ──────────────────────────────────────────────────────────────

    #[Computed]
    public function bookings()
    {
        return Booking::where('section_id', $this->section->id)
            ->orderByDesc('starts_at')
            ->limit(30)
            ->get();
    }

    // ── Tournaments ───────────────────────────────────────────────────────────

    #[Computed]
    public function tournaments()
    {
        return Tournament::where('section_id', $this->section->id)
            ->orderByDesc('created_at')
            ->get();
    }

    // ── Submissions ───────────────────────────────────────────────────────────

    #[Computed]
    public function submissions()
    {
        return Submission::where('section_id', $this->section->id)
            ->orderByDesc('created_at')
            ->get();
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    #[Computed]
    public function openTasks()
    {
        return MeetingTask::where('section_id', $this->section->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->get();
    }

    // ── About ─────────────────────────────────────────────────────────────────

    #[Computed]
    public function linkedOrgRole(): ?OrganizationalRole
    {
        return OrganizationalRole::where('section_id', $this->section->id)->first();
    }

    #[Computed]
    public function sharedDrive(): ?WorkspaceSharedDrive
    {
        return WorkspaceSharedDrive::where('section_id', $this->section->id)->first();
    }
}; ?>

<x-slot:header>
    <x-page-header title="{{ $section->name }}" subtitle="{{ $section->description }}">
        @if($section->wikiSpace && ! $section->wikiSpace->isArchived())
            @can('view', $section->wikiSpace)
                <a href="{{ route('wiki.space', $section->wikiSpace) }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <x-bootstrap-icon name="journal-text" class="size-4" />
                    Gruppens wiki
                </a>
            @endcan
        @endif
    </x-page-header>
</x-slot:header>

<div>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

        {{-- Tabs --}}
        <div class="border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-6 overflow-x-auto">
                <button wire:click="$set('tab','posts')"
                        @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                            'border-brand text-zinc-900 dark:text-white'                       => $tab === 'posts',
                            'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'posts'])>
                    Inlägg
                    @php $postCount = $this->section->posts()->count(); @endphp
                    @if($postCount > 0)
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $postCount }}</span>
                    @endif
                </button>

                <button wire:click="$set('tab','members')"
                        @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                            'border-brand text-zinc-900 dark:text-white'                       => $tab === 'members',
                            'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'members'])>
                    Medlemmar
                    <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $this->section->activeMembers()->count() }}</span>
                    @if($this->isGroupAdmin && $this->section->pendingMembers()->count() > 0)
                        <span class="ml-0.5 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">{{ $this->section->pendingMembers()->count() }} väntar</span>
                    @endif
                </button>

                @php $eventCount = Event::where('organizer_section_id', $section->id)->orWhere('access_section_id', $section->id)->whereIn('status', ['published','completed'])->count(); @endphp
                @if($eventCount > 0 || $this->isMember)
                    <button wire:click="$set('tab','events')"
                            @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                                'border-brand text-zinc-900 dark:text-white'                       => $tab === 'events',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'events'])>
                        Evenemang
                        @if($eventCount > 0)
                            <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $eventCount }}</span>
                        @endif
                    </button>
                @endif

                @if($this->canViewMeetings && $this->section->meetings()->count() > 0)
                    <button wire:click="$set('tab','meetings')"
                            @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                                'border-brand text-zinc-900 dark:text-white'                       => $tab === 'meetings',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'meetings'])>
                        Möten
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $this->section->meetings()->count() }}</span>
                    </button>
                @endif

                @php $tournamentCount = Tournament::where('section_id', $section->id)->count(); @endphp
                @if($tournamentCount > 0 || $this->isMember)
                    <button wire:click="$set('tab','tournaments')"
                            @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                                'border-brand text-zinc-900 dark:text-white'                       => $tab === 'tournaments',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'tournaments'])>
                        Turneringar
                        @if($tournamentCount > 0)
                            <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $tournamentCount }}</span>
                        @endif
                    </button>
                @endif

                @if($this->isMember)
                    @php $bookingCount = Booking::where('section_id', $section->id)->count(); @endphp
                    <button wire:click="$set('tab','bookings')"
                            @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                                'border-brand text-zinc-900 dark:text-white'                       => $tab === 'bookings',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'bookings'])>
                        Bokningar
                        @if($bookingCount > 0)
                            <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $bookingCount }}</span>
                        @endif
                    </button>
                @endif

                @php $submissionCount = Submission::where('section_id', $section->id)->count(); @endphp
                @if($section->accepts_issues)
                    <button wire:click="$set('tab','submissions')"
                            @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                                'border-brand text-zinc-900 dark:text-white'                       => $tab === 'submissions',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'submissions'])>
                        Ärenden
                        @if($submissionCount > 0)
                            <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $submissionCount }}</span>
                        @endif
                    </button>
                @endif

                @php $childCount = $section->children()->where('is_active', true)->count(); @endphp
                @if($childCount > 0 || ($this->isGroupAdmin && $section->canHaveChildren()))
                    <button wire:click="$set('tab','subgroups')"
                            @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                                'border-brand text-zinc-900 dark:text-white'                       => $tab === 'subgroups',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'subgroups'])>
                        Undergrupper
                        @if($childCount > 0)
                            <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $childCount }}</span>
                        @endif
                    </button>
                @endif

                <button wire:click="$set('tab','about')"
                        @class(['border-b-2 pb-3 text-sm font-medium whitespace-nowrap transition-colors',
                            'border-brand text-zinc-900 dark:text-white'                       => $tab === 'about',
                            'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'about'])>
                    Om gruppen
                </button>
            </nav>
        </div>

        {{-- TAB: Inlägg --}}
        @if($tab === 'posts')
            <div class="space-y-4">

                @if($flash)
                    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ $flash }}</div>
                @endif

                @if($this->canPost)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="p-4 space-y-3">
                            <textarea wire:model="postBody" rows="3"
                                      placeholder="Skriv något till gruppen…"
                                      class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white resize-none"></textarea>
                            <div class="flex items-center gap-3">
                                <div class="relative flex-1">
                                    <x-bootstrap-icon name="tag" class="absolute left-2.5 top-1/2 -translate-y-1/2 size-3.5 text-zinc-400" />
                                    <input wire:model="postTagsInput" type="text" placeholder="Taggar, kommaseparerade…"
                                           class="w-full rounded-lg border border-zinc-200 bg-zinc-50 pl-8 pr-3 py-1.5 text-xs focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                </div>
                                <button wire:click="createPost" class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                    Publicera
                                </button>
                            </div>
                            @error('postBody') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                @forelse($this->posts as $post)
                    @php
                        $myPost       = Auth::id() === $post->author_id;
                        $postReactions = $post->reactionSummary();
                        $commentsOpen  = in_array($post->id, $openComments);
                    @endphp
                    <div class="overflow-hidden rounded-xl border {{ $post->is_pinned ? 'border-brand/40' : 'border-zinc-200 dark:border-zinc-700' }} bg-white dark:bg-zinc-900">

                        <div class="flex items-center gap-3 px-4 pt-4 pb-2">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-complement/10 dark:bg-complement/20 text-sm font-semibold text-complement dark:text-complement-light">
                                {{ mb_substr($post->author->name ?? '?', 0, 1) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $post->author->name ?? '–' }}</span>
                                <span class="ml-2 text-xs text-zinc-400 dark:text-zinc-500">{{ $post->created_at->diffForHumans() }}</span>
                                @if($post->is_pinned)
                                    <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-brand/10 px-2 py-0.5 text-xs text-brand-dark dark:text-brand">
                                        <x-bootstrap-icon name="pin-fill" class="size-2.5" /> Fäst
                                    </span>
                                @endif
                            </div>
                            @if($myPost || $this->isGroupAdmin || Auth::user()?->hasPermissionTo('sections.manage'))
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button @click="open = !open" class="rounded p-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                        <x-bootstrap-icon name="three-dots" class="size-4" />
                                    </button>
                                    <div x-show="open" x-cloak x-transition
                                         class="absolute right-0 z-10 mt-1 w-36 rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                        @if($this->isGroupAdmin || Auth::user()?->hasPermissionTo('sections.manage'))
                                            <button wire:click="togglePin({{ $post->id }})" @click="open = false"
                                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                                <x-bootstrap-icon name="{{ $post->is_pinned ? 'pin-angle' : 'pin-fill' }}" class="size-3.5" />
                                                {{ $post->is_pinned ? 'Lossa' : 'Fäst' }}
                                            </button>
                                        @endif
                                        <button wire:click="deletePost({{ $post->id }})" wire:confirm="Ta bort inlägget?" @click="open = false"
                                                class="flex w-full items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-zinc-50 dark:text-red-400 dark:hover:bg-zinc-700">
                                            <x-bootstrap-icon name="trash" class="size-3.5" /> Ta bort
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="px-4 py-2 text-sm text-zinc-800 dark:text-zinc-200 whitespace-pre-wrap leading-relaxed">{{ $post->body }}</div>

                        @if($post->tags->isNotEmpty())
                            <div class="px-4 pb-2 flex flex-wrap gap-1.5">
                                @foreach($post->tags as $tag)
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">#{{ $tag->name }}</span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-2 border-t border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
                            @foreach($postReactions as $emoji => $data)
                                <button wire:click="togglePostReaction({{ $post->id }}, '{{ $emoji }}')"
                                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-sm transition-colors
                                               {{ $data['mine'] ? 'bg-brand/10 border border-brand/30 dark:bg-brand/20' : 'bg-zinc-100 border border-transparent hover:border-zinc-300 dark:bg-zinc-800 dark:hover:border-zinc-600' }}">
                                    {{ $emoji }}<span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $data['count'] }}</span>
                                </button>
                            @endforeach
                            @auth
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button @click="open = !open" class="inline-flex items-center rounded-full border border-dashed border-zinc-300 px-2 py-1 text-xs text-zinc-400 hover:border-zinc-400 hover:text-zinc-600 dark:border-zinc-600 dark:hover:border-zinc-400 transition-colors">
                                        <x-bootstrap-icon name="emoji-smile" class="size-3.5 mr-1" />+
                                    </button>
                                    <div x-show="open" x-cloak x-transition class="absolute bottom-full left-0 mb-1 flex gap-1 rounded-lg border border-zinc-200 bg-white p-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 z-10">
                                        @foreach(Reaction::allowed() as $emoji)
                                            <button wire:click="togglePostReaction({{ $post->id }}, '{{ $emoji }}')" @click="open = false"
                                                    class="rounded p-1 text-lg hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">{{ $emoji }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endauth
                            <button wire:click="toggleComments({{ $post->id }})"
                                    class="ml-auto flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors">
                                <x-bootstrap-icon name="chat" class="size-3.5" />
                                {{ $post->comments->count() ?: '' }} {{ $commentsOpen ? 'Stäng' : 'Kommentarer' }}
                            </button>
                        </div>

                        @if($commentsOpen)
                            <div class="border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/30 px-4 py-3 space-y-3">
                                @foreach($post->comments as $comment)
                                    @php $commentReactions = $comment->reactionSummary(); @endphp
                                    <div class="flex gap-2.5">
                                        <div class="flex size-6 shrink-0 mt-0.5 items-center justify-center rounded-full bg-zinc-200 dark:bg-zinc-700 text-xs font-semibold text-zinc-600 dark:text-zinc-300">
                                            {{ mb_substr($comment->author->name ?? '?', 0, 1) }}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="rounded-xl rounded-tl-none bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                                                <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $comment->author->name ?? '–' }}</span>
                                                <p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200 whitespace-pre-wrap">{{ $comment->body }}</p>
                                            </div>
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                @foreach($commentReactions as $emoji => $data)
                                                    <button wire:click="toggleCommentReaction({{ $comment->id }}, '{{ $emoji }}')"
                                                            class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs transition-colors
                                                                   {{ $data['mine'] ? 'bg-brand/10 border border-brand/30 dark:bg-brand/20' : 'bg-zinc-100 border border-transparent hover:border-zinc-300 dark:bg-zinc-800' }}">
                                                        {{ $emoji }}<span class="text-zinc-500 dark:text-zinc-400">{{ $data['count'] }}</span>
                                                    </button>
                                                @endforeach
                                                @auth
                                                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                                        <button @click="open = !open" class="rounded-full border border-dashed border-zinc-300 px-1.5 py-0.5 text-xs text-zinc-400 hover:border-zinc-400 dark:border-zinc-600 transition-colors">+</button>
                                                        <div x-show="open" x-cloak x-transition class="absolute bottom-full left-0 mb-1 flex gap-1 rounded-lg border border-zinc-200 bg-white p-1.5 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 z-10">
                                                            @foreach(Reaction::allowed() as $emoji)
                                                                <button wire:click="toggleCommentReaction({{ $comment->id }}, '{{ $emoji }}')" @click="open = false"
                                                                        class="rounded p-0.5 text-base hover:bg-zinc-100 dark:hover:bg-zinc-700">{{ $emoji }}</button>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endauth
                                                @if(Auth::id() === $comment->author_id || $this->isGroupAdmin || Auth::user()?->hasPermissionTo('sections.manage'))
                                                    <button wire:click="deleteComment({{ $comment->id }})" wire:confirm="Ta bort kommentaren?"
                                                            class="text-xs text-zinc-300 hover:text-red-400 dark:text-zinc-600 dark:hover:text-red-400 transition-colors ml-1">
                                                        <x-bootstrap-icon name="x-lg" class="size-3" />
                                                    </button>
                                                @endif
                                                <span class="text-xs text-zinc-400 dark:text-zinc-500 ml-auto">{{ $comment->created_at->diffForHumans() }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                                @if($this->canPost)
                                    <div class="flex gap-2.5 pt-1">
                                        <div class="flex size-6 shrink-0 mt-1 items-center justify-center rounded-full bg-complement/10 dark:bg-complement/20 text-xs font-semibold text-complement dark:text-complement-light">
                                            {{ mb_substr(Auth::user()->name ?? '?', 0, 1) }}
                                        </div>
                                        <div class="flex flex-1 gap-2">
                                            <input wire:model="commentBody.{{ $post->id }}"
                                                   wire:keydown.enter="addComment({{ $post->id }})"
                                                   type="text" placeholder="Skriv en kommentar…"
                                                   class="flex-1 rounded-full border border-zinc-300 bg-white px-4 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                            <button wire:click="addComment({{ $post->id }})"
                                                    class="rounded-full bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                                <x-bootstrap-icon name="send" class="size-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-12 text-center">
                        <x-bootstrap-icon name="chat-square-text" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-3" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga inlägg ännu.</p>
                        @if($this->canPost)
                            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Var den första att skriva något!</p>
                        @endif
                    </div>
                @endforelse
            </div>
        @endif

        {{-- TAB: Medlemmar --}}
        @if($tab === 'members')
            <div class="space-y-4">

                @if($flash)
                    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ $flash }}</div>
                @endif
                @error('removeRole')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">{{ $message }}</div>
                @enderror

                {{-- Admin: sök och lägg till --}}
                @if($this->isGroupAdmin)
                    <div class="relative">
                        <input wire:model.live.debounce.300ms="memberSearch"
                               type="text" placeholder="Sök och lägg till medlem…"
                               class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                        @if($this->memberSearchResults->isNotEmpty())
                            <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($this->memberSearchResults as $m)
                                    <button wire:click="addMemberToGroup({{ $m->id }})"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                                        <span class="text-xs text-zinc-400">{{ $m->email }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Väntande ansökningar --}}
                @if($this->isGroupAdmin && $this->pendingMembers->isNotEmpty())
                    <div class="overflow-hidden rounded-xl border border-amber-200 bg-white dark:border-amber-800 dark:bg-zinc-900">
                        <div class="border-b border-amber-100 bg-amber-50 px-5 py-3 dark:border-amber-900 dark:bg-amber-950/30">
                            <span class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">Väntar på godkännande — {{ $this->pendingMembers->count() }}</span>
                        </div>
                        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($this->pendingMembers as $member)
                                <li class="flex items-center gap-3 px-5 py-3">
                                    <span class="flex-1 text-sm font-medium text-zinc-900 dark:text-white">{{ $member->full_name }}</span>
                                    <button wire:click="approveMember({{ $member->id }})"
                                            class="rounded-lg bg-brand px-2.5 py-1 text-xs font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">Godkänn</button>
                                    <button wire:click="rejectMember({{ $member->id }})"
                                            wire:confirm="Neka ansökan från {{ $member->full_name }}?"
                                            class="rounded-lg border border-zinc-200 px-2.5 py-1 text-xs text-zinc-500 hover:border-red-300 hover:text-red-500 dark:border-zinc-700 transition-colors">Neka</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Aktiva medlemmar --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Aktiva — {{ $this->members->count() }}</span>
                    </div>
                    @if($this->members->isEmpty())
                        <p class="px-5 py-6 text-center text-sm text-zinc-400 dark:text-zinc-500">Inga aktiva medlemmar.</p>
                    @else
                        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($this->members as $member)
                                @php $memberRoles = $member->sectionRoles; @endphp
                                <li class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-complement/10 dark:bg-complement/20 text-sm font-semibold text-complement dark:text-complement-light">
                                            {{ mb_substr($member->full_name ?? '?', 0, 1) }}
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $member->full_name }}</p>
                                            @if($memberRoles->isNotEmpty())
                                                <div class="mt-0.5 flex flex-wrap gap-1">
                                                    @foreach($memberRoles as $r)
                                                        <span class="inline-flex items-center gap-0.5 rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                                            @if($r->is_admin) <x-bootstrap-icon name="shield-fill" class="size-2.5 text-complement dark:text-complement-light" /> @endif
                                                            {{ $r->title }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                        @if($this->isGroupAdmin)
                                            <div class="flex shrink-0 items-center gap-1">
                                                <button wire:click="startNewRole({{ $member->id }})"
                                                        title="Lägg till roll"
                                                        class="rounded p-1.5 text-xs text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-300 transition-colors">
                                                    <x-bootstrap-icon name="person-badge" class="size-3.5" />
                                                </button>
                                                <button wire:click="removeMemberFromGroup({{ $member->id }})"
                                                        wire:confirm="Ta bort {{ $member->full_name }} från gruppen?"
                                                        title="Ta bort från grupp"
                                                        class="rounded p-1.5 text-zinc-300 hover:bg-zinc-100 hover:text-red-500 dark:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-red-400 transition-colors">
                                                    <x-bootstrap-icon name="x-lg" class="size-3.5" />
                                                </button>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Rollista (admin: med redigera/ta bort) --}}
                                    @if($this->isGroupAdmin && $memberRoles->isNotEmpty())
                                        <ul class="mt-2 ml-11 space-y-1">
                                            @foreach($memberRoles as $r)
                                                <li class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                                    <span>{{ $r->title }}</span>
                                                    <span class="text-zinc-300 dark:text-zinc-600">
                                                        {{ $r->valid_from->format('d/m Y') }}
                                                        @if($r->valid_until) – {{ $r->valid_until->format('d/m Y') }} @endif
                                                    </span>
                                                    <button wire:click="editRole({{ $r->id }})" class="text-complement hover:underline dark:text-complement-light">Redigera</button>
                                                    <button wire:click="removeRole({{ $r->id }})"
                                                            wire:confirm="Ta bort rollen '{{ $r->title }}'?"
                                                            class="text-zinc-400 hover:text-red-500 dark:hover:text-red-400">Ta bort</button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Rollformulär --}}
                @if($this->isGroupAdmin && ($roleMemberId || $editRoleId))
                    @php $formMember = $this->members->find($roleMemberId); @endphp
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50 space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ $editRoleId ? 'Redigera roll' : 'Ny roll' }}
                            @if($formMember) — {{ $formMember->full_name }} @endif
                        </h3>
                        <div>
                            <label class="lbl">Titel <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="roleTitle" class="inp mt-1" placeholder="t.ex. Ordförande, Kassör…" />
                            @error('roleTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Från <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="roleValidFrom" class="inp mt-1" />
                                @error('roleValidFrom') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Till <span class="text-zinc-400 font-normal">(tomt = tills vidare)</span></label>
                                <input type="date" wire:model="roleValidUntil" class="inp mt-1" />
                                @error('roleValidUntil') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <input id="roleIsAdmin" type="checkbox" wire:model="roleIsAdmin" class="rounded border-zinc-300 text-complement" />
                            <label for="roleIsAdmin" class="text-sm text-zinc-700 dark:text-zinc-300">
                                Gruppadmin
                                <span class="ml-1 text-xs text-zinc-400">(kan hantera roller och ansökningar)</span>
                            </label>
                        </div>
                        <div class="flex gap-3">
                            <button wire:click="saveRole" class="btn btn-primary btn-sm">Spara</button>
                            <button wire:click="cancelRoleEdit" class="btn btn-ghost btn-sm">Avbryt</button>
                        </div>
                    </div>
                @endif

            </div>
        @endif

        {{-- TAB: Evenemang --}}
        @if($tab === 'events')
            <div class="space-y-3">
                @can('events.create')
                    <div class="flex justify-end">
                        <a href="{{ route('admin.events.create') }}?section={{ $section->id }}" wire:navigate
                           class="btn btn-secondary btn-sm">
                            <x-bootstrap-icon name="plus-lg" class="size-3.5" /> Skapa evenemang
                        </a>
                    </div>
                @endcan
                @forelse($this->events as $event)
                    @php $occ = $event->occurrences->first(); @endphp
                    <a href="{{ route('events.show', $event->slug) }}" wire:navigate
                       class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white px-5 py-4 hover:border-complement/40 dark:border-zinc-700 dark:bg-zinc-900 transition-colors">
                        <div class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg bg-brand/10 dark:bg-brand/20">
                            @if($occ)
                                <span class="text-xs font-bold leading-none text-brand-dark dark:text-brand">{{ $occ->starts_at->format('d') }}</span>
                                <span class="text-[10px] uppercase text-brand-dark/70 dark:text-brand/70">{{ $occ->starts_at->locale('sv')->isoFormat('MMM') }}</span>
                            @else
                                <x-bootstrap-icon name="calendar-event" class="size-5 text-brand-dark dark:text-brand" />
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $event->title }}</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $occ ? $occ->starts_at->locale('sv')->isoFormat('D MMMM YYYY, HH:mm') : '–' }}
                                @if($event->location_name) · {{ $event->location_name }} @endif
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs
                              {{ $event->status === 'published' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                            {{ $event->status === 'published' ? 'Publicerat' : 'Avslutat' }}
                        </span>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-12 text-center">
                        <x-bootstrap-icon name="calendar-event" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-3" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga evenemang kopplade till den här gruppen.</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- TAB: Möten --}}
        @if($tab === 'meetings' && $this->canViewMeetings)
            <div class="space-y-3">
                @forelse($this->meetings as $meeting)
                    <a href="{{ route('admin.meetings.show', $meeting) }}" wire:navigate
                       class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white px-5 py-4 hover:border-complement/40 dark:border-zinc-700 dark:bg-zinc-900 transition-colors">
                        <div class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg bg-complement/10 dark:bg-complement/20">
                            <span class="text-xs font-bold leading-none text-complement dark:text-complement-light">{{ $meeting->scheduled_at->format('d') }}</span>
                            <span class="text-[10px] uppercase text-complement/70 dark:text-complement-light/70">{{ $meeting->scheduled_at->locale('sv')->isoFormat('MMM') }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $meeting->title }}</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $meeting->scheduled_at->isoFormat('D MMMM YYYY, HH:mm') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ $meeting->statusLabel() }}
                        </span>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-12 text-center">
                        <x-bootstrap-icon name="people-fill" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-3" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga möten kopplade till den här gruppen.</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- Öppna uppgifter (under möten-tabben) --}}
        @if($tab === 'meetings' && $this->canViewMeetings && $this->openTasks->isNotEmpty())
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        Öppna uppgifter — {{ $this->openTasks->count() }}
                    </span>
                </div>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($this->openTasks as $task)
                        <li class="flex items-start gap-3 px-5 py-3">
                            <span class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                {{ match($task->status) {
                                    'in_progress' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                    default       => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
                                } }}">
                                {{ $task->statusLabel() }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <a href="{{ route('admin.tasks.show', $task) }}" wire:navigate
                                   class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                    {{ $task->title }}
                                </a>
                            </div>
                            @if($task->due_date)
                                <span class="shrink-0 text-xs {{ $task->isOverdue() ? 'text-red-500 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-500' }}">
                                    {{ $task->due_date->isoFormat('D MMM') }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- TAB: Turneringar --}}
        @if($tab === 'tournaments')
            <div class="space-y-3">
                @can('tournaments.manage')
                    <div class="flex justify-end">
                        <a href="{{ route('admin.tournaments.create') }}?section={{ $section->id }}" wire:navigate
                           class="btn btn-secondary btn-sm">
                            <x-bootstrap-icon name="plus-lg" class="size-3.5" /> Skapa turnering
                        </a>
                    </div>
                @endcan
                @forelse($this->tournaments as $tournament)
                    <a href="{{ route('admin.tournaments.manage', $tournament) }}" wire:navigate
                       class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white px-5 py-4 hover:border-complement/40 dark:border-zinc-700 dark:bg-zinc-900 transition-colors">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand/10 dark:bg-brand/20">
                            <x-bootstrap-icon name="trophy" class="size-5 text-brand-dark dark:text-brand" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $tournament->title }}</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $tournament->participants()->count() }} deltagare
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ $tournament->statusLabel() }}
                        </span>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-12 text-center">
                        <x-bootstrap-icon name="trophy" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-3" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga turneringar kopplade till den här gruppen.</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- TAB: Bokningar --}}
        @if($tab === 'bookings')
            <div class="space-y-3">
                @auth
                    <div class="flex justify-end">
                        <a href="{{ route('booking.create') }}?section={{ $section->id }}" wire:navigate
                           class="btn btn-secondary btn-sm">
                            <x-bootstrap-icon name="plus-lg" class="size-3.5" /> Boka lokal
                        </a>
                    </div>
                @endauth
                @forelse($this->bookings as $booking)
                    <div class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white px-5 py-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <span class="text-xs font-bold leading-none text-zinc-700 dark:text-zinc-300">{{ $booking->starts_at->format('d') }}</span>
                            <span class="text-[10px] uppercase text-zinc-500">{{ $booking->starts_at->locale('sv')->isoFormat('MMM') }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $booking->title }}</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $booking->starts_at->isoFormat('D MMM YYYY, HH:mm') }}–{{ $booking->ends_at->format('H:i') }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs
                              {{ $booking->status === 'confirmed' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                            {{ match($booking->status) {
                                'confirmed' => 'Bekräftad',
                                'pending'   => 'Väntar',
                                'rejected'  => 'Nekad',
                                'cancelled' => 'Avbokad',
                                default     => $booking->status,
                            } }}
                        </span>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-12 text-center">
                        <x-bootstrap-icon name="door-open" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-3" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga lokalbokningar kopplade till den här gruppen.</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- TAB: Ärenden --}}
        @if($tab === 'submissions')
            <div class="space-y-3">
                @forelse($this->submissions as $submission)
                    <a href="{{ route('admin.submissions.show', $submission) }}" wire:navigate
                       class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white px-5 py-4 hover:border-complement/40 dark:border-zinc-700 dark:bg-zinc-900 transition-colors">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $submission->title }}</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $submission->typeLabel() }} · {{ $submission->created_at->isoFormat('D MMM YYYY') }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ $submission->statusLabel() }}
                        </span>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-12 text-center">
                        <x-bootstrap-icon name="inbox" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-3" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga ärenden kopplade till den här gruppen.</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- TAB: Undergrupper --}}
        @if($tab === 'subgroups')
            <div class="space-y-3">

                @if($this->isGroupAdmin)
                    <div class="flex justify-end">
                        <a href="{{ route('groups.index') }}?parent={{ $section->id }}" wire:navigate
                           class="btn btn-secondary btn-sm">
                            <x-bootstrap-icon name="plus-lg" class="size-3.5" /> Skapa undergrupp
                        </a>
                    </div>
                @endif

                @forelse($section->children()->where('is_active', true)->with('sectionMemberships')->orderBy('sort_order')->orderBy('name')->get() as $child)
                    @php
                        $memberCount = $child->activeMembers()->count();
                        $member = Auth::user()?->member;
                        $ms = $member ? $child->sectionMemberships->where('member_id', $member->id)->whereNull('left_at')->first() : null;
                    @endphp
                    <div class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white px-5 py-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('groups.show', $child) }}" wire:navigate
                               class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                {{ $child->name }}
                            </a>
                            @if($child->description)
                                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500 truncate">{{ $child->description }}</p>
                            @endif
                            <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $memberCount }} {{ $memberCount === 1 ? 'medlem' : 'medlemmar' }}
                                @if($child->visibility === 'secret')
                                    · <span class="text-amber-500 dark:text-amber-400">Hemlig</span>
                                @elseif($child->visibility === 'private')
                                    · Privat
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if($ms?->status === 'active')
                                <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                    <x-bootstrap-icon name="check-circle" class="size-3" /> Medlem
                                </span>
                            @elseif($ms?->status === 'pending')
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Ansökan skickad</span>
                            @elseif($member && in_array($child->membership_type, ['open', 'application']))
                                <a href="{{ route('groups.show', $child) }}" wire:navigate
                                   class="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800 transition-colors">
                                    {{ $child->membership_type === 'open' ? 'Gå med' : 'Ansök' }}
                                </a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-12 text-center">
                        <x-bootstrap-icon name="diagram-3" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-3" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga undergrupper ännu.</p>
                        @if($this->isGroupAdmin)
                            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Skapa den första undergruppen med knappen ovan.</p>
                        @endif
                    </div>
                @endforelse

            </div>
        @endif

        {{-- TAB: Om gruppen --}}
        @if($tab === 'about')
            <div class="space-y-4">

                @if($editingAbout)
                    {{-- Redigeringsformulär --}}
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Redigera grupp</h3>
                        <div>
                            <label class="lbl">Namn <span class="text-red-500">*</span></label>
                            <input wire:model="editName" type="text" class="inp mt-1" />
                            @error('editName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lbl">Beskrivning</label>
                            <textarea wire:model="editDesc" rows="3" class="inp mt-1 resize-none"></textarea>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Synlighet</label>
                                <select wire:model="editVisibility" class="inp mt-1">
                                    <option value="public">Publik — alla inloggade</option>
                                    <option value="members">Enbart föreningsmedlemmar</option>
                                    <option value="private">Privat — syns men bara för medlemmar</option>
                                    <option value="secret">Hemlig — osynlig för utomstående</option>
                                </select>
                            </div>
                            <div>
                                <label class="lbl">Hur går man med?</label>
                                <select wire:model="editMembership" class="inp mt-1">
                                    <option value="open">Öppet — självgå</option>
                                    <option value="application">Ansökan — kräver godkännande</option>
                                    <option value="closed">Stängt — admin bjuder in</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="lbl">Taggar</label>
                            <div class="relative mt-1">
                                <x-bootstrap-icon name="tag" class="absolute left-2.5 top-1/2 -translate-y-1/2 size-3.5 text-zinc-400" />
                                <input wire:model="editTagsInput" type="text" placeholder="Kommaseparerade taggar…"
                                       class="w-full rounded-lg border border-zinc-300 bg-white pl-8 pr-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                            </div>
                        </div>
                        <div class="flex gap-3 pt-1">
                            <button wire:click="saveAbout" class="btn btn-primary btn-sm">Spara</button>
                            <button wire:click="$set('editingAbout', false)" class="btn btn-ghost btn-sm">Avbryt</button>
                        </div>
                    </div>
                @else
                    {{-- Visningsläge --}}
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        @if($this->isGroupAdmin)
                            <div class="flex justify-end border-b border-zinc-100 px-5 py-2 dark:border-zinc-800">
                                <button wire:click="startEditAbout"
                                        class="inline-flex items-center gap-1.5 text-xs text-zinc-500 hover:text-complement dark:hover:text-complement-light transition-colors">
                                    <x-bootstrap-icon name="pencil" class="size-3.5" /> Redigera
                                </button>
                            </div>
                        @endif
                        <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @if($section->description)
                                <div class="px-5 py-4">
                                    <dt class="mb-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">Beskrivning</dt>
                                    <dd class="text-sm text-zinc-700 dark:text-zinc-300">{{ $section->description }}</dd>
                                </div>
                            @endif
                            <div class="flex items-center justify-between px-5 py-3">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Synlighet</dt>
                                <dd class="text-sm text-zinc-900 dark:text-white">
                                    {{ match($section->visibility) {
                                        'public'  => 'Publik',
                                        'members' => 'Enbart medlemmar',
                                        'private' => 'Privat',
                                        'secret'  => 'Hemlig',
                                        default   => $section->visibility,
                                    } }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between px-5 py-3">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Medlemskap</dt>
                                <dd class="text-sm text-zinc-900 dark:text-white">
                                    {{ match($section->membership_type) {
                                        'open'        => 'Öppet',
                                        'application' => 'Ansökan',
                                        'closed'      => 'Stängt',
                                        'mandatory'   => 'Obligatoriskt',
                                        'admin_only'  => 'Adminantaget',
                                        default       => $section->membership_type,
                                    } }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between px-5 py-3">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Antal medlemmar</dt>
                                <dd class="text-sm text-zinc-900 dark:text-white">{{ $section->activeMembers()->count() }}</dd>
                            </div>
                            @if($this->linkedOrgRole)
                                <div class="flex items-center justify-between px-5 py-3">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Kopplad org-roll</dt>
                                    <dd class="text-sm">
                                        <a href="{{ route('admin.org-roles.show', $this->linkedOrgRole) }}" wire:navigate
                                           class="text-complement hover:underline dark:text-complement-light">
                                            {{ $this->linkedOrgRole->name }}
                                        </a>
                                    </dd>
                                </div>
                            @endif
                            @if($this->sharedDrive?->google_drive_id)
                                <div class="flex items-center justify-between px-5 py-3">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Google Drive</dt>
                                    <dd>
                                        <a href="https://drive.google.com/drive/folders/{{ $this->sharedDrive->google_drive_id }}"
                                           target="_blank" rel="noopener"
                                           class="inline-flex items-center gap-1.5 text-sm text-complement hover:underline dark:text-complement-light">
                                            <x-bootstrap-icon name="folder2" class="size-3.5 text-yellow-500" />
                                            {{ $this->sharedDrive->name }}
                                        </a>
                                    </dd>
                                </div>
                            @endif
                            @if($section->tags->isNotEmpty())
                                <div class="px-5 py-3">
                                    <dt class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">Taggar</dt>
                                    <dd class="flex flex-wrap gap-1.5">
                                        @foreach($section->tags as $tag)
                                            <span class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">#{{ $tag->name }}</span>
                                        @endforeach
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                @endif
            </div>
        @endif

    </div>
</div>
