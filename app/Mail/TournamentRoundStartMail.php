<?php

namespace App\Mail;

use App\Mail\Concerns\WithMailTracking;
use App\Models\Tournament\TournamentMatch;
use App\Models\Tournament\TournamentParticipant;
use App\Models\Tournament\TournamentRound;
use App\Services\MailTemplateService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TournamentRoundStartMail extends Mailable
{
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(
        public TournamentRound       $round,
        public TournamentParticipant $participant,
    ) {
        $this->initTracking();
    }

    public function envelope(): Envelope
    {
        $rendered = app(MailTemplateService::class)->render('tournament_round_start', $this->variables());
        $subject  = $rendered['subject']
            ?? "{$this->round->phase->tournament->title} — Runda {$this->round->round_number} startar";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $rendered       = app(MailTemplateService::class)->render('tournament_round_start', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Din match för rundan är klar.</p>';

        $match = $this->participantMatch();

        $this->detailsRows = array_values(array_filter([
            ['Turnering', $this->round->phase->tournament->title],
            ['Fas', $this->round->phase->name],
            ['Runda', $this->round->round_number],
            $match ? ['Bord', $match->table_number ? 'Bord ' . $match->table_number : '—'] : null,
            $match && $match->time_slot && ($this->round->slot_times[$match->time_slot] ?? null)
                ? ['Starttid', $this->round->slot_times[$match->time_slot]]
                : null,
        ]));

        $this->ctaUrl   = route('tournaments.show', $this->round->phase->tournament);
        $this->ctaLabel = 'Se turneringen';

        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array
    {
        $member = $this->participant->member;
        $match  = $this->participantMatch();

        $opponents = $match
            ? $match->participants
                ->where('participant_id', '!=', $this->participant->id)
                ->map(fn ($mp) => $mp->participant?->displayName())
                ->filter()
                ->implode(', ')
            : null;

        $slotTime = null;
        if ($match && $match->time_slot) {
            $slotTime = $this->round->slot_times[$match->time_slot] ?? null;
        }

        $matchInfo = $match
            ? implode('', array_filter([
                $match->table_number ? "Bord {$match->table_number}" : null,
                $opponents ? " mot {$opponents}" : null,
            ]))
            : 'Ingen match denna runda (Bye)';

        $slotInfo = $slotTime
            ? "Pass {$match->time_slot} startar kl. {$slotTime}"
            : '';

        return [
            'fornamn'          => $member?->first_name ?? 'Deltagare',
            'turnering_namn'   => $this->round->phase->tournament->title,
            'runda_nummer'     => $this->round->round_number,
            'match_info'       => $matchInfo,
            'slot_info'        => $slotInfo,
        ];
    }

    private function participantMatch(): ?TournamentMatch
    {
        return $this->round->matches()
            ->whereHas('participants', fn ($q) => $q->where('participant_id', $this->participant->id))
            ->with('participants.participant.member')
            ->first();
    }
}
