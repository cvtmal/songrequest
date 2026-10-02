<?php

declare(strict_types=1);

namespace App\Requests\Command;

final readonly class UnblockGuest
{
    public function __construct(
        public string $guestId,
        public string $accountId,
    ) {
    }
}
