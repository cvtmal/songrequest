<?php

declare(strict_types=1);

namespace App\Requests\Exception;

use App\Shared\Exception\DomainException;

/**
 * Thrown for a missing record and for another account's record alike, so a DJ cannot probe IDs.
 */
final class GuestNotFound extends DomainException
{
    public static function withId(string $guestId): self
    {
        return new self(\sprintf('Guest "%s" does not exist.', $guestId));
    }
}
