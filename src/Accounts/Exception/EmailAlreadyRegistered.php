<?php

declare(strict_types=1);

namespace App\Accounts\Exception;

use App\Shared\Exception\DomainException;

final class EmailAlreadyRegistered extends DomainException
{
    public static function forEmail(string $email): self
    {
        return new self(\sprintf('A user with email "%s" already exists.', $email));
    }
}
