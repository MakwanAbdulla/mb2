<?php

namespace App\Enums;

enum QuestionType: string
{
    case Text           = 'text';
    case Textarea       = 'textarea';
    case Radio          = 'radio';
    case Checkbox       = 'checkbox';
    case Select         = 'select';
    case Scale          = 'scale';
    case Datetime       = 'datetime';
    case Number         = 'number';
    case Email          = 'email';
    case Section        = 'section';
    case Product          = 'product';
    case EventSelection   = 'event_selection';
    case SectionSelection = 'section_selection';
    case Membership       = 'membership';
    case RentalWaitlist   = 'rental_waitlist';

    public function label(): string
    {
        return match ($this) {
            self::Text             => 'Kort svar',
            self::Textarea         => 'Långt svar',
            self::Radio            => 'Enkelsvar (radio)',
            self::Checkbox         => 'Flersvar (kryssrutor)',
            self::Select           => 'Dropdown',
            self::Scale            => 'Skala / betyg',
            self::Datetime         => 'Datum & tid',
            self::Number           => 'Siffra',
            self::Email            => 'E-postadress',
            self::Section          => 'Rubrik / avdelare',
            self::Product          => 'Produkt (förbeställning)',
            self::EventSelection   => 'Välj event',
            self::SectionSelection => 'Välj sektioner att gå med i',
            self::Membership       => 'Bli medlem (lägg i varukorg)',
            self::RentalWaitlist   => 'Intresselista för uthyrning',
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::Radio, self::Checkbox, self::Select]);
    }

    public function isScale(): bool
    {
        return $this === self::Scale;
    }

    public function isDatetime(): bool
    {
        return $this === self::Datetime;
    }

    public function isProduct(): bool
    {
        return $this === self::Product;
    }

    public function isEventSelection(): bool
    {
        return $this === self::EventSelection;
    }

    public function isSection(): bool
    {
        return $this === self::Section;
    }

    public function isSectionSelection(): bool
    {
        return $this === self::SectionSelection;
    }

    public function isMembership(): bool
    {
        return $this === self::Membership;
    }

    public function isRentalWaitlist(): bool
    {
        return $this === self::RentalWaitlist;
    }

    /** Types that collect no answer (structural only). */
    public function isStructural(): bool
    {
        return $this === self::Section;
    }
}
