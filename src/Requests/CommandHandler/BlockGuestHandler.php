<?php

declare(strict_types=1);

namespace App\Requests\CommandHandler;

use App\Requests\Command\BlockGuest;
use App\Requests\Exception\GuestNotFound;
use App\Requests\Repository\AccountGuestBlockRepository;
use App\Requests\Repository\RequestVoteRepository;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * No event lock: the submit handler reads isBlocked under the event lock, so a block commits
 * either before or after a submit, and both outcomes are correct.
 */
final class BlockGuestHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly RequestVoteRepository $votes,
        private readonly AccountGuestBlockRepository $blocks,
    ) {
    }

    public function __invoke(BlockGuest $command): void
    {
        if (!$this->votes->guestVotedForAccount($command->accountId, $command->guestId)) {
            throw GuestNotFound::withId($command->guestId);
        }

        $this->blocks->block($command->accountId, $command->guestId);
    }
}
