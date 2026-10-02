<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

final class GuestRequestLimitReached extends DomainException
{
    public static function forEvent(string $eventId): self
    {
        return new self(\sprintf('Guest reached the request limit for event "%s".', $eventId));
    }
}
