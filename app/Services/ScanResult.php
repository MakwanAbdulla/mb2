<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Value object returned by ScannerService::resolve().
 *
 * The 'type' tells the UI which result card to render.
 * The 'status' tells whether the scanned item can be acted upon.
 */
class ScanResult
{
    // Types — one per scannable namespace
    public const TYPE_DISCOUNT             = 'discount_code';
    public const TYPE_EVENT_REGISTRATION   = 'event_registration';
    public const TYPE_MEMBER               = 'member';
    public const TYPE_ITEM_ADDON           = 'item_addon';        // ITM- → EventRegistrationAddon
    public const TYPE_ITEM_FULFILLMENT     = 'item_fulfillment';  // ITM- → OrderFulfillment
    public const TYPE_NOT_FOUND            = 'not_found';
    public const TYPE_EMPTY                = 'empty';

    // Statuses
    public const STATUS_AVAILABLE       = 'available';
    public const STATUS_ALREADY_USED    = 'already_used';
    public const STATUS_INVALID         = 'invalid';
    public const STATUS_NOT_REGISTERED  = 'not_registered'; // member found, not registered for event
    public const STATUS_EXPIRED         = 'expired';         // member card QR — valid issuer but past window

    private function __construct(
        public readonly string  $type,
        public readonly string  $status,
        public readonly ?Model  $model,
        public readonly ?string $message,
        public readonly ?string $rawCode,
    ) {}

    public static function make(
        string  $type,
        string  $status,
        ?Model  $model   = null,
        ?string $message = null,
        ?string $rawCode = null,
    ): self {
        return new self($type, $status, $model, $message, $rawCode);
    }

    public static function notFound(string $rawCode): self
    {
        return new self(
            type:    self::TYPE_NOT_FOUND,
            status:  self::STATUS_INVALID,
            model:   null,
            message: "Koden \"{$rawCode}\" hittades inte.",
            rawCode: $rawCode,
        );
    }

    public static function empty(): self
    {
        return new self(self::TYPE_EMPTY, self::STATUS_INVALID, null, null, null);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function isError(): bool
    {
        return in_array($this->status, [self::STATUS_ALREADY_USED, self::STATUS_INVALID])
            || $this->type === self::TYPE_NOT_FOUND;
    }
}
