<?php

declare(strict_types=1);

namespace App\Requests\Command;

final readonly class BlockGuest
{
    public function __construct(
        public string $guestId,
        public string $accountId,
    ) {
    }
}
