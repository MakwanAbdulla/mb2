<?php

namespace App\Support;

class NotificationCategory
{
    public const NEWSLETTER            = 'newsletter';
    public const EVENTS_ANNOUNCEMENTS  = 'events.announcements';
    public const EVENTS_REMINDERS      = 'events.reminders';
    public const MEMBERSHIP            = 'membership';
    public const SYSTEM                = 'system';

    public const WARNING_NONE   = 'none';
    public const WARNING_SOFT   = 'soft';
    public const WARNING_STRONG = 'strong';

    /** Categories the user can opt out of (system cannot be opted out). */
    public const OPT_OUTABLE = [
        self::NEWSLETTER,
        self::EVENTS_ANNOUNCEMENTS,
        self::EVENTS_REMINDERS,
        self::MEMBERSHIP,
    ];

    private static array $definitions = [
        self::NEWSLETTER => [
            'label'       => 'Nyhetsbrev',
            'description' => 'Allmänna nyheter och information från föreningen.',
            'warning'     => self::WARNING_NONE,
        ],
        self::EVENTS_ANNOUNCEMENTS => [
            'label'       => 'Eventutskick',
            'description' => 'Information om kommande evenemang och aktiviteter.',
            'warning'     => self::WARNING_NONE,
        ],
        self::EVENTS_REMINDERS => [
            'label'       => 'Eventpåminnelser',
            'description' => 'Påminnelser för evenemang du är anmäld till.',
            'warning'     => self::WARNING_SOFT,
            'warning_text'=> 'Du får inte längre påminnelser om evenemang du anmält dig till.',
        ],
        self::MEMBERSHIP => [
            'label'       => 'Medlemskapsmail',
            'description' => 'Förnyelsepåminnelser, betalningsinformation och liknande.',
            'warning'     => self::WARNING_STRONG,
            'warning_text'=> 'Du får inte längre påminnelser om förnyelse eller betalning. Ditt medlemskap kan förfalla utan förvarning.',
        ],
        self::SYSTEM => [
            'label'       => 'Systemmeddelanden',
            'description' => 'Kontorelaterade meddelanden (lösenord, säkerhet). Kan inte stängas av.',
            'warning'     => self::WARNING_NONE,
        ],
    ];

    public static function label(string $slug): string
    {
        return static::$definitions[$slug]['label'] ?? $slug;
    }

    public static function description(string $slug): string
    {
        return static::$definitions[$slug]['description'] ?? '';
    }

    public static function warningLevel(string $slug): string
    {
        return static::$definitions[$slug]['warning'] ?? self::WARNING_NONE;
    }

    public static function warningText(string $slug): string
    {
        return static::$definitions[$slug]['warning_text'] ?? '';
    }

    public static function canOptOut(string $slug): bool
    {
        return in_array($slug, self::OPT_OUTABLE, true);
    }

    /** All categories as [slug => label] for select inputs. */
    public static function forSelect(): array
    {
        return collect(self::OPT_OUTABLE)
            ->mapWithKeys(fn ($slug) => [$slug => static::label($slug)])
            ->all();
    }
}
