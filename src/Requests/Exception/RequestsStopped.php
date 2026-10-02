<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

final class RequestsStopped extends DomainException
{
    public static function forEvent(string $eventId): self
    {
        return new self(\sprintf('Requests for event "%s" are stopped.', $eventId));
    }
}
