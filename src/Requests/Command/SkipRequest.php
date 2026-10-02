<?php

declare(strict_types=1);

namespace App\Requests\Command;

final readonly class SkipRequest
{
    public function __construct(
        public string $requestId,
        public string $eventId,
        public string $accountId,
    ) {
    }
}
