<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

final class RequestCooldownActive extends DomainException
{
    private int $secondsLeft = 0;

    public static function forEvent(string $eventId, int $secondsLeft): self
    {
        $e = new self(\sprintf('Guest must wait %d s before the next request to event "%s".', $secondsLeft, $eventId));
        $e->secondsLeft = $secondsLeft;

        return $e;
    }

    /**
     * Lets the guest page say how long to wait (AS-2).
     */
    public function getSecondsLeft(): int
    {
        return $this->secondsLeft;
    }
}
