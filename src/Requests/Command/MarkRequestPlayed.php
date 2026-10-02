<?php

declare(strict_types=1);

namespace App\Requests\Command;

final readonly class MarkRequestPlayed
{
    public function __construct(
        public string $requestId,
        public string $eventId,
        public string $accountId,
    ) {
    }
}
