<?php

declare(strict_types=1);

namespace App\Events\Command;

final readonly class CloseEvent
{
    public function __construct(
        public string $eventId,
        public string $accountId,
    ) {
    }
}
