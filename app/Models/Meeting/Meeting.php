<?php

namespace App\Models\Meeting;

use App\Models\Booking\Booking;
use App\Models\Content\Mailing;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                     $id
 * @property int|null                $template_id
 * @property int|null                $section_id
 * @property string                  $title
 * @property string                  $meeting_type
 * @property string                  $status
 * @property \Carbon\Carbon          $scheduled_at
 * @property \Carbon\Carbon|null     $ends_at
 * @property string|null             $location
 * @property int|null                $booking_id
 * @property int|null                $notice_mailing_id
 * @property bool                    $include_absences_in_minutes
 * @property \Carbon\Carbon|null     $published_at
 * @property int                     $created_by
 */
class Meeting extends Model
{
    protected $fillable = [
        'template_id', 'section_id', 'title', 'meeting_type', 'is_extraordinary', 'status',
        'scheduled_at', 'ends_at', 'motion_deadline', 'location', 'meet_link', 'booking_id', 'notice_mailing_id',
        'include_absences_in_minutes', 'approved_at', 'published_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_at'               => 'datetime',
        'ends_at'                    => 'datetime',
        'motion_deadline'            => 'date',
        'approved_at'                => 'datetime',
        'published_at'               => 'datetime',
        'include_absences_in_minutes' => 'boolean',
        'is_extraordinary'           => 'boolean',
    ];

    public const STATUSES = [
        'draft'            => 'Utkast',
        'notice_sent'      => 'Kallelse skickad',
        'ongoing'          => 'Pågående',
        'pending_approval' => 'Väntar på justering',
        'approved'         => 'Justerat',
        'published'        => 'Publicerat',
        'cancelled'        => 'Inställt',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MeetingTemplate::class, 'template_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function noticeMailing(): BelongsTo
    {
        return $this->belongsTo(Mailing::class, 'notice_mailing_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class)->orderBy('sort_order');
    }

    public function minutesItems(): HasMany
    {
        return $this->hasMany(MeetingMinutesItem::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MeetingDocument::class);
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Nästa logiska status i flödet. */
    public function nextStatus(): ?string
    {
        return match($this->status) {
            'draft'            => 'notice_sent',
            'notice_sent'      => 'ongoing',
            'ongoing'          => 'pending_approval',
            'pending_approval' => 'approved',
            'approved'         => 'published',
            default            => null,
        };
    }

    /** Svenska etiketter för åtgärdsknappar längs flödet. */
    public function nextStatusLabel(): ?string
    {
        return match($this->status) {
            'draft'            => 'Skicka kallelse',
            'notice_sent'      => 'Starta mötet',
            'ongoing'          => 'Avsluta och skicka till justering',
            'pending_approval' => 'Markera som justerat',
            'approved'         => 'Publicera protokoll',
            default            => null,
        };
    }

    public function typeLabel(): string
    {
        $base = MeetingTemplate::TYPES[$this->meeting_type] ?? $this->meeting_type;
        return $this->is_extraordinary ? "Extra {$base}" : $base;
    }

    public function acceptsMotions(): bool
    {
        return \in_array($this->meeting_type, MeetingTemplate::MOTION_TYPES);
    }

    public function motionsOpen(): bool
    {
        if (! $this->acceptsMotions()) {
            return false;
        }
        if ($this->motion_deadline === null) {
            return true;
        }
        return now()->startOfDay()->lte($this->motion_deadline);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Kopiera agendapunkter från mall. Anropas vid skapande. */
    public function importFromTemplate(MeetingTemplate $template): void
    {
        foreach ($template->items as $item) {
            $this->agendaItems()->create([
                'sort_order'       => $item->sort_order,
                'title'            => $item->title,
                'description'      => $item->description,
                'minutes_template' => $item->minutes_template,
                'item_type'        => $item->item_type,
                'is_required'      => $item->is_required,
            ]);
        }

        $this->applyRoleMappings($template);
    }

    /**
     * Slå upp aktiva sektionsroller och sätt mötesroll på matchande deltagare.
     * Körs efter inviteMembers() så att deltagarna redan finns.
     */
    public function applyRoleMappings(MeetingTemplate $template): void
    {
        if (! $this->section_id) {
            return;
        }

        foreach ($template->roleMappings as $mapping) {
            $keyword = mb_strtolower($mapping->section_role_keyword);

            $roleHolder = \App\Models\Organization\SectionRole::where('section_id', $this->section_id)
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', now())
                ->get()
                ->first(fn ($r) => str_contains(mb_strtolower($r->title), $keyword));

            if (! $roleHolder) {
                continue;
            }

            $this->attendees()
                ->where('member_id', $roleHolder->member_id)
                ->update(['role' => $mapping->meeting_role]);
        }
    }

    /**
     * Bjud in relevanta medlemmar som deltagare.
     * Sektionsmöten bjuder in sektionens aktiva medlemmar; föreningsnivå bjuder in alla aktiva.
     * Befintliga poster påverkas inte (firstOrCreate).
     */
    public function inviteMembers(): void
    {
        $members = $this->section_id
            ? $this->section->activeMembers()->get()
            : Member::where('status', 'active')->get();

        foreach ($members as $member) {
            $this->attendees()->firstOrCreate(
                ['member_id' => $member->id],
                ['invited' => true, 'present' => null, 'voting_rights' => true],
            );
        }
    }

    /** Hämta röstlängden: närvarande med rösträtt. */
    public function votingList()
    {
        return $this->attendees()->where('present', true)->where('voting_rights', true);
    }
}
