<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class DiscordCommandHandler
{
    /**
     * Only the person who ran the command sees the reply, so the channel
     * does not fill up with lookups.
     */
    private const FLAG_EPHEMERAL = 64;

    /**
     * Discord option type for free text.
     */
    private const OPTION_STRING = 3;

    public function __construct(
        private BookingDaySummary $summary,
        private BookingOverviewService $overview,
    ) {}

    /**
     * The commands that are registered with Discord.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'idag',
                'description' => 'Visa dagens bokningar, lediga rum och evenemang',
            ],
            [
                'name' => 'vecka',
                'description' => 'Visa bokningar för kommande 7 dagar',
            ],
            [
                'name' => 'ledigt',
                'description' => 'Visa vilka rum som är lediga en viss dag',
                'options' => [[
                    'type' => self::OPTION_STRING,
                    'name' => 'datum',
                    'description' => 'T.ex. 5/11, 2026-11-05 eller imorgon',
                    'required' => false,
                ]],
            ],
        ];
    }

    /**
     * Message data for the reply to an application command. Members with a
     * linked account see everything, everyone else only what is public.
     */
    public function handle(array $data, ?string $discordUserId = null): array
    {
        $full = $this->linkedUser($discordUserId)?->isMember() ?? false;

        $message = match ($data['name'] ?? null) {
            'idag' => $this->day(now(), $full),
            'vecka' => $this->week($full),
            'ledigt' => $this->freeRooms($this->option($data, 'datum'), $full),
            default => ['content' => 'Okänt kommando.'],
        };

        return $message + [
            'flags' => self::FLAG_EPHEMERAL,
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /**
     * The Membrum account linked to the Discord user who ran the command.
     */
    public function linkedUser(?string $discordUserId): ?User
    {
        if (! $discordUserId) {
            return null;
        }

        return User::with('member')->where('discord_id', $discordUserId)->first();
    }

    private function day(CarbonInterface $date, bool $full): array
    {
        $message = ['embeds' => [$this->summary->dayEmbed($date, $full)]];

        if ($full || ! $this->summary->dayHasMembersOnlyContent($date)) {
            return $message;
        }

        return $this->withLinkHint($message);
    }

    private function week(bool $full): array
    {
        $message = ['embeds' => [$this->overview->buildEmbed($full)]];

        [$from, $to] = $this->overview->period();

        if ($full || $this->summary->bookingsBetween($from, $to)->isEmpty()) {
            return $message;
        }

        return $this->withLinkHint($message);
    }

    /**
     * End the reply with a link for connecting the Discord account. Only used
     * when something was actually left out, so it does not turn into nagging.
     */
    private function withLinkHint(array $message): array
    {
        $message['embeds'][] = [
            'description' => 'Du ser bara det som är publikt. [Koppla ditt Discord-konto]('.route('auth.discord.link').') till Membrum för att se allt.',
        ];

        return $message;
    }

    private function freeRooms(?string $input, bool $full): array
    {
        $date = $this->parseDate($input);

        if (! $date) {
            $shown = Str::limit((string) $input, 30);

            return ['content' => "Förstod inte datumet \"{$shown}\". Skriv t.ex. 5/11, 5/11 2026, 2026-11-05 eller imorgon."];
        }

        return $this->day($date, $full);
    }

    /**
     * Accepts "idag", "imorgon", d/m, d/m Y and Y-m-d. A day and month
     * without a year that has already passed means next year. Returns null
     * for anything else, including dates that do not exist.
     */
    private function parseDate(?string $input): ?CarbonInterface
    {
        $input = mb_strtolower(trim((string) $input));

        if ($input === '' || $input === 'idag') {
            return now();
        }

        if ($input === 'imorgon') {
            return now()->addDay();
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\s+(\d{4}))?$/', $input, $matches)) {
            [$day, $month] = [(int) $matches[1], (int) $matches[2]];
            $year = isset($matches[3]) ? (int) $matches[3] : $this->upcomingYear($day, $month);
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $matches)) {
            [$year, $month, $day] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day);
    }

    private function upcomingYear(int $day, int $month): int
    {
        $year = now()->year;

        if (checkdate($month, $day, $year) && Carbon::create($year, $month, $day)->lt(today())) {
            return $year + 1;
        }

        return $year;
    }

    private function option(array $data, string $name): ?string
    {
        foreach ($data['options'] ?? [] as $option) {
            if (($option['name'] ?? null) === $name) {
                return (string) $option['value'];
            }
        }

        return null;
    }
}
