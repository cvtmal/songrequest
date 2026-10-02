<?php

declare(strict_types=1);

namespace App\Events\Exception;

use App\Shared\Exception\DomainException;

/**
 * Thrown for a missing event and for another account's event alike, so a DJ cannot probe IDs.
 */
final class EventNotFound extends DomainException
{
    public static function withId(string $eventId): self
    {
        return new self(\sprintf('Event "%s" does not exist.', $eventId));
    }
}
