<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

/**
 * Thrown for a missing record and for another account's record alike, so a DJ cannot probe IDs.
 */
final class SongRequestNotFound extends DomainException
{
    public static function withId(string $requestId): self
    {
        return new self(\sprintf('Request "%s" does not exist.', $requestId));
    }
}
