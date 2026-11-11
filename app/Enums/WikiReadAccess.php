<?php

namespace App\Enums;

/** Who may read a wiki space. Write access follows the owning group, not this. */
enum WikiReadAccess: string
{
    // Anyone, also without logging in
    case Public = 'public';

    // All active members
    case Members = 'members';

    // Everyone who is an active member of any group with a title or term (funktionärer)
    case Officials = 'officials';

    // Only the owning group's active members
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Publik',
            self::Members => 'Alla medlemmar',
            self::Officials => 'Alla funktionärer',
            self::Group => 'Bara gruppen',
        };
    }
}
