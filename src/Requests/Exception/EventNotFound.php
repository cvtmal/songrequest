<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

final class EventNotFound extends DomainException
{
    public static function withId(string $eventId): self
    {
        return new self(\sprintf('Event "%s" does not exist.', $eventId));
    }
}
