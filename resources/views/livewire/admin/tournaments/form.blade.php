<?php

use App\Models\Events\Event;
use App\Models\Organization\Section;
use App\Models\Tournament\Tournament;
use App\Models\Tournament\TournamentPhase;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Turnering');

new class extends Component {

    public ?int    $tournamentId       = null;
    public string  $title              = '';
    public string  $description        = '';
    public string  $status             = Tournament::STATUS_DRAFT;
    public string  $participantType    = Tournament::TYPE_INDIVIDUAL;
    public string  $maxParticipants    = '';
    public bool    $isPublic           = true;
    public string  $eventId            = '';
    public string  $sectionId          = '';

    // Phases (JSON-array of phase configs)
    public array $phases = [];

    // Registration fields
    public array $regFields = [];

    public function mount(?Tournament $tournament = null): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('tournaments.manage'), 403);

        if (! $tournament?->exists && $sectionId = request()->query('section')) {
            $this->sectionId = $sectionId;
        }

        if ($tournament && $tournament->exists) {
            $this->tournamentId    = $tournament->id;
            $this->title           = $tournament->title;
            $this->description     = $tournament->description ?? '';
            $this->status          = $tournament->status;
            $this->participantType = $tournament->participant_type;
            $this->maxParticipants = $tournament->max_participants ? (string) $tournament->max_participants : '';
            $this->isPublic        = $tournament->is_public;
            $this->eventId         = $tournament->event_id ? (string) $tournament->event_id : '';
            $this->sectionId       = $tournament->section_id ? (string) $tournament->section_id : '';
            $this->regFields       = $tournament->registration_fields ?? [];

            $this->phases = $tournament->phases->map(fn ($p) => [
                'id'                 => $p->id,
                'name'               => $p->name,
                'format'             => $p->format,
                'players_per_match'  => $p->players_per_match,
                'rounds_count'       => $p->rounds_count ?? '',
                'match_points_win'   => $p->match_points_win,
                'match_points_draw'  => $p->match_points_draw,
                'match_points_loss'  => $p->match_points_loss,
                'uses_game_points'   => $p->uses_game_points,
                'advancement_count'  => $p->advancement_count ?? '',
                'group_count'        => $p->group_count ?? '',
                'tables_available'      => $p->tables_available ?? '',
                'min_players_per_match' => ($p->min_players_per_match ?? 2) > 2 ? $p->min_players_per_match : '',
                'placement_points'   => $p->settings['placement_points']
                    ?? \App\Services\TournamentService::defaultPlacementPoints($p->players_per_match, $p->match_points_win, $p->match_points_loss),
            ])->toArray();
        }

        if (empty($this->phases)) {
            $this->addPhase();
        }
    }

    public function addPhase(): void
    {
        $this->phases[] = [
            'id'                => null,
            'name'              => 'Fas ' . (count($this->phases) + 1),
            'format'            => TournamentPhase::FORMAT_SWISS,
            'players_per_match' => 2,
            'rounds_count'      => 5,
            'match_points_win'  => 3,
            'match_points_draw' => 1,
            'match_points_loss' => 0,
            'uses_game_points'  => false,
            'advancement_count' => '',
            'group_count'       => '',
            'tables_available'       => '',
            'min_players_per_match'  => '',
            'placement_points'       => [3, 2, 1, 0],
        ];
    }

    public function removePhase(int $idx): void
    {
        array_splice($this->phases, $idx, 1);
    }

    public function addRegField(): void
    {
        $this->regFields[] = ['key' => 'field_' . count($this->regFields), 'label' => '', 'type' => 'text', 'required' => false, 'options' => ''];
    }

    public function removeRegField(int $idx): void
    {
        array_splice($this->regFields, $idx, 1);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tournaments.manage'), 403);

        $this->validate([
            'title'            => 'required|string|max:120',
            'phases'           => 'required|array|min:1',
            'phases.*.name'    => 'required|string|max:80',
            'phases.*.format'  => 'required|in:single_elim,double_elim,round_robin,swiss,pod_swiss',
        ]);

        $data = [
            'title'              => $this->title,
            'description'        => $this->description ?: null,
            'status'             => $this->status,
            'participant_type'   => $this->participantType,
            'max_participants'   => $this->maxParticipants ? (int) $this->maxParticipants : null,
            'is_public'          => $this->isPublic,
            'event_id'           => $this->eventId ?: null,
            'section_id'         => $this->sectionId ?: null,
            'registration_fields' => empty($this->regFields) ? null : $this->regFields,
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            if ($this->tournamentId) {
                $tournament = Tournament::findOrFail($this->tournamentId);
                $tournament->update($data);
            } else {
                $tournament = Tournament::create($data + ['created_by_user_id' => Auth::id()]);
            }

            // Sync phases
            $existingIds = collect($this->phases)->pluck('id')->filter()->all();
            $tournament->phases()->whereNotIn('id', $existingIds)->delete();

            foreach ($this->phases as $idx => $phaseData) {
                $phasePayload = [
                    'phase_number'       => $idx + 1,
                    'name'               => $phaseData['name'],
                    'format'             => $phaseData['format'],
                    'players_per_match'  => (int) ($phaseData['players_per_match'] ?? 2),
                    'rounds_count'       => $phaseData['rounds_count'] !== '' ? (int) $phaseData['rounds_count'] : null,
                    'match_points_win'   => (int) ($phaseData['match_points_win'] ?? 3),
                    'match_points_draw'  => (int) ($phaseData['match_points_draw'] ?? 1),
                    'match_points_loss'  => (int) ($phaseData['match_points_loss'] ?? 0),
                    'uses_game_points'   => (bool) ($phaseData['uses_game_points'] ?? false),
                    'advancement_count'  => $phaseData['advancement_count'] !== '' ? (int) $phaseData['advancement_count'] : null,
                    'group_count'        => $phaseData['group_count'] !== '' ? (int) $phaseData['group_count'] : null,
                    'tables_available'      => ($phaseData['tables_available'] ?? '') !== '' ? (int) $phaseData['tables_available'] : null,
                    'min_players_per_match' => ($phaseData['min_players_per_match'] ?? '') !== '' ? (int) $phaseData['min_players_per_match'] : 2,
                    'settings'           => ($playersPerMatch = (int) ($phaseData['players_per_match'] ?? 2)) > 2
                        ? ['placement_points' => array_values(array_map('intval', $phaseData['placement_points'] ?? []))]
                        : null,
                ];

                if (! empty($phaseData['id'])) {
                    $tournament->phases()->where('id', $phaseData['id'])->update($phasePayload);
                } else {
                    $tournament->phases()->create($phasePayload);
                }
            }

            $this->tournamentId = $tournament->id;
        });

        $this->redirect(route('admin.tournaments.manage', $this->tournamentId), navigate: true);
    }

    #[\Livewire\Attributes\Computed]
    public function events()
    {
        return Event::whereIn('status', ['published', 'draft'])->orderByDesc('created_at')->limit(50)->get(['id', 'title']);
    }

    #[\Livewire\Attributes\Computed]
    public function sections()
    {
        return Section::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6">

    <h1 class="text-xl font-bold text-zinc-900 dark:text-white">
        {{ $tournamentId ? 'Redigera turnering' : 'Ny turnering' }}
    </h1>

    <form wire:submit="save" class="space-y-6">

        {{-- Grundinfo --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
            <div class="p-5 space-y-4">
                <div>
                    <label class="lbl">Titel <span class="text-red-500">*</span></label>
                    <input wire:model="title" type="text" maxlength="120" autofocus class="inp mt-1 w-full @error('title') border-red-300 @enderror" />
                    @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Beskrivning</label>
                    <textarea wire:model="description" rows="2" class="inp mt-1 w-full resize-none"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="lbl">Status</label>
                        <select wire:model="status" class="inp mt-1 w-full">
                            <option value="draft">Utkast</option>
                            <option value="registration">Anmälan öppen</option>
                            <option value="ongoing">Pågående</option>
                            <option value="completed">Avslutad</option>
                            <option value="cancelled">Inställd</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Deltagartyp</label>
                        <select wire:model="participantType" class="inp mt-1 w-full">
                            <option value="individual">Individuell</option>
                            <option value="team">Lag</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="lbl">Max deltagare</label>
                        <input wire:model="maxParticipants" type="number" min="2" max="1024" placeholder="Obegränsat" class="inp mt-1 w-32" />
                    </div>
                    <div class="flex items-center gap-3 pt-6">
                        <input type="checkbox" wire:model="isPublic" id="is_public" class="size-4 rounded accent-complement" />
                        <label for="is_public" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">Synlig publikt</label>
                    </div>
                </div>
            </div>

            {{-- Ägare --}}
            <div class="p-5 space-y-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">Ägare (valfritt)</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="lbl">Event</label>
                        <select wire:model="eventId" class="inp mt-1 w-full">
                            <option value="">— Inget event —</option>
                            @foreach($this->events as $e)
                                <option value="{{ $e->id }}">{{ $e->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Sektion</label>
                        <select wire:model="sectionId" class="inp mt-1 w-full">
                            <option value="">— Ingen sektion —</option>
                            @foreach($this->sections as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- Faser --}}
        <div class="space-y-3">
            <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Faser</p>
            @foreach($phases as $idx => $phase)
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-4 py-2.5">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Fas {{ $idx + 1 }}</span>
                        @if(count($phases) > 1)
                            <button type="button" wire:click="removePhase({{ $idx }})" class="text-xs text-red-500 hover:text-red-700">Ta bort</button>
                        @endif
                    </div>
                    <div class="p-4 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="lbl">Namn</label>
                            <input wire:model="phases.{{ $idx }}.name" type="text" maxlength="80" class="inp mt-1 w-full" />
                        </div>
                        <div>
                            <label class="lbl">Format</label>
                            <select wire:model.live="phases.{{ $idx }}.format" class="inp mt-1 w-full">
                                <option value="swiss">Swiss</option>
                                <option value="pod_swiss">Pod-Swiss</option>
                                <option value="single_elim">Single Elimination</option>
                                <option value="double_elim">Double Elimination</option>
                                <option value="round_robin">Round Robin</option>
                            </select>
                        </div>
                        @if(in_array($phases[$idx]['format'] ?? '', ['pod_swiss']))
                            <div>
                                <label class="lbl">Spelare per bord (max)</label>
                                <input wire:model.live="phases.{{ $idx }}.players_per_match" type="number" min="2" max="8" class="inp mt-1 w-20" />
                            </div>
                            <div>
                                <label class="lbl">Spelare per bord (min)</label>
                                <input wire:model="phases.{{ $idx }}.min_players_per_match" type="number" min="2" max="8"
                                       placeholder="{{ max(2, (int)($phases[$idx]['players_per_match'] ?? 4) - 1) }}"
                                       class="inp mt-1 w-20" />
                                <p class="mt-0.5 text-xs text-zinc-400">Förhindrar bord med för få spelare.</p>
                            </div>
                        @endif
                        @if(in_array($phases[$idx]['format'] ?? '', ['swiss', 'pod_swiss']))
                            <div>
                                <label class="lbl">Antal ronder</label>
                                <input wire:model="phases.{{ $idx }}.rounds_count" type="number" min="1" max="20" class="inp mt-1 w-20" />
                            </div>
                        @endif
                        @if($phases[$idx]['format'] === 'round_robin')
                            <div>
                                <label class="lbl">Antal grupper</label>
                                <input wire:model="phases.{{ $idx }}.group_count" type="number" min="1" max="16" placeholder="1" class="inp mt-1 w-20" />
                            </div>
                        @endif
                        @if(count($phases) > 1 && $idx < count($phases) - 1)
                            <div>
                                <label class="lbl">Antal som avancerar</label>
                                <input wire:model="phases.{{ $idx }}.advancement_count" type="number" min="1" placeholder="Alla" class="inp mt-1 w-20" />
                            </div>
                        @endif
                        @php $ppm = (int) ($phases[$idx]['players_per_match'] ?? 2); @endphp
                        @if($ppm > 2)
                            {{-- Placement-based points for N-player games --}}
                            <div class="sm:col-span-2">
                                <label class="lbl text-xs">Poäng per placering</label>
                                <p class="text-xs text-zinc-400 mb-2">Index 0 = 1:a plats, sista = {{ $ppm }}:a plats</p>
                                <div class="flex gap-2 flex-wrap">
                                    @for($pl = 0; $pl < $ppm; $pl++)
                                        <div class="text-center">
                                            <label class="text-xs text-zinc-500 block mb-1">{{ $pl + 1 }}:a</label>
                                            <input wire:model="phases.{{ $idx }}.placement_points.{{ $pl }}"
                                                   type="number" min="0" max="99"
                                                   class="inp w-14 text-center text-sm" />
                                        </div>
                                    @endfor
                                </div>
                            </div>
                        @else
                            <div class="sm:col-span-2 grid grid-cols-3 gap-2">
                                <div>
                                    <label class="lbl text-xs">Poäng vinst</label>
                                    <input wire:model="phases.{{ $idx }}.match_points_win" type="number" min="0" max="10" class="inp mt-1 w-16" />
                                </div>
                                <div>
                                    <label class="lbl text-xs">Oavgjort</label>
                                    <input wire:model="phases.{{ $idx }}.match_points_draw" type="number" min="0" max="10" class="inp mt-1 w-16" />
                                </div>
                                <div>
                                    <label class="lbl text-xs">Förlust</label>
                                    <input wire:model="phases.{{ $idx }}.match_points_loss" type="number" min="0" max="10" class="inp mt-1 w-16" />
                                </div>
                            </div>
                        @endif
                        <div>
                            <label class="lbl text-xs">Bord tillgängliga</label>
                            <input wire:model="phases.{{ $idx }}.tables_available" type="number" min="1" max="999" placeholder="Obegränsat"
                                   class="inp mt-1 w-24" />
                            <p class="mt-0.5 text-xs text-zinc-400">Lämna tomt om ni alltid har tillräckligt.</p>
                        </div>
                        <div class="sm:col-span-2 flex items-center gap-2">
                            <input type="checkbox" wire:model="phases.{{ $idx }}.uses_game_points" id="ugp_{{ $idx }}" class="size-4 rounded accent-complement" />
                            <label for="ugp_{{ $idx }}" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">Spelpoäng som tiebreaker</label>
                        </div>
                    </div>
                </div>
            @endforeach
            <button type="button" wire:click="addPhase"
                    class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 flex items-center gap-1.5">
                <x-bootstrap-icon name="plus-circle" class="size-4" />
                Lägg till fas
            </button>
        </div>

        {{-- Anmälningsfält --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
            <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Anmälningsfält</h3>
                <button type="button" wire:click="addRegField" class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 flex items-center gap-1">
                    <x-bootstrap-icon name="plus-lg" class="size-3.5" />Lägg till
                </button>
            </div>
            @if(empty($regFields))
                <p class="px-5 py-4 text-sm text-zinc-400 dark:text-zinc-500">Inga extra fält. Deltagare behöver inte ange mer info.</p>
            @else
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($regFields as $fi => $field)
                        <div class="grid grid-cols-3 gap-2 items-start px-4 py-3">
                            <div>
                                <label class="lbl text-xs">Etikett</label>
                                <input wire:model="regFields.{{ $fi }}.label" type="text" maxlength="60" class="inp mt-1 w-full text-sm" />
                            </div>
                            <div>
                                <label class="lbl text-xs">Typ</label>
                                <select wire:model.live="regFields.{{ $fi }}.type" class="inp mt-1 w-full text-sm">
                                    <option value="text">Text</option>
                                    <option value="select">Välj bland</option>
                                    <option value="textarea">Fritext</option>
                                </select>
                                @if(($regFields[$fi]['type'] ?? 'text') === 'select')
                                    <input wire:model="regFields.{{ $fi }}.options" type="text" placeholder="Alt1,Alt2,Alt3" class="inp mt-1 w-full text-xs" />
                                @endif
                            </div>
                            <div class="flex items-end gap-2 pt-5">
                                <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400 cursor-pointer">
                                    <input type="checkbox" wire:model="regFields.{{ $fi }}.required" class="size-3.5 accent-complement" />
                                    Obligatorisk
                                </label>
                                <button type="button" wire:click="removeRegField({{ $fi }})" class="ml-auto text-red-400 hover:text-red-600 text-xs">Ta bort</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.tournaments.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">Avbryt</a>
            <button type="submit" class="btn btn-primary">Spara</button>
        </div>
    </form>
</div>
