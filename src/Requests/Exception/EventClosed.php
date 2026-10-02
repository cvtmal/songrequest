<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

final class EventClosed extends DomainException
{
    public static function forEvent(string $eventId): self
    {
        return new self(\sprintf('Event "%s" no longer accepts requests.', $eventId));
    }
}
