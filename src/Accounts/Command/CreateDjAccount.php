<?php

declare(strict_types=1);

namespace App\Accounts\Command;

final readonly class CreateDjAccount
{
    public function __construct(
        public string $email,
        public string $stageName,
        #[\SensitiveParameter]
        public string $plainPassword,
    ) {
    }
}
