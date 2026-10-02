<?php

declare(strict_types=1);

namespace App\Requests\CommandHandler;

use App\Requests\Command\UnblockGuest;
use App\Requests\Repository\AccountGuestBlockRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class UnblockGuestHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly AccountGuestBlockRepository $blocks,
    ) {
    }

    public function __invoke(UnblockGuest $command): void
    {
        // Scoped by account: another account's guest is a silent no-op, and repeating it is harmless.
        $this->blocks->unblock($command->accountId, $command->guestId);
    }
}
