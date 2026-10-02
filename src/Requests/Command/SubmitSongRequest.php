<?php

declare(strict_types=1);

namespace App\Requests\Command;

final readonly class SubmitSongRequest
{
    public function __construct(
        public string $eventId,
        public string $guestToken,
        public string $title,
        public ?string $artist,
        public ?string $nickname,
        public ?string $ipAddress,
    ) {
    }
}
