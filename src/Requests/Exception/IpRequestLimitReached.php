<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

final class IpRequestLimitReached extends DomainException
{
    public static function forEvent(string $eventId): self
    {
        return new self(\sprintf('Too many requests from this network for event "%s".', $eventId));
    }
}
