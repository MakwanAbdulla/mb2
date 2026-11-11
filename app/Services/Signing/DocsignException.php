<?php

namespace App\Services\Signing;

use RuntimeException;

/**
 * DocSign returned `success: false` (usually with HTTP 200) or an HTTP error.
 */
class DocsignException extends RuntimeException
{
    /**
     * @param  array<string, list<string>|string>  $errors  Field => messages, from `data` on validation errors
     */
    public function __construct(string $message, public readonly array $errors = [], public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /** Human-readable summary, e.g. for `signing_requests.last_error`. */
    public function summary(): string
    {
        if ($this->errors === []) {
            return $this->getMessage();
        }

        return collect($this->errors)
            // document/edit sends a plain string per field instead of a list
            ->map(fn (array|string $messages, string $field) => $field.': '.implode(' ', (array) $messages))
            ->implode('; ');
    }

    /** Indexes of unknown parties from a `parties.N är ogiltigt` validation error. */
    public function invalidPartyIndexes(): array
    {
        return collect(array_keys($this->errors))
            ->filter(fn (string $field) => preg_match('/^parties\.\d+$/', $field))
            ->map(fn (string $field) => (int) substr($field, strlen('parties.')))
            ->values()
            ->all();
    }
}
