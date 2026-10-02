<?php

declare(strict_types=1);

namespace App\Events\Command;

final readonly class CreateEvent
{
    public function __construct(
        public string $accountId,
        public string $name,
    ) {
    }
}
