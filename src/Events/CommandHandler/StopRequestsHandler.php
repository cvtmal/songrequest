<?php

declare(strict_types=1);

namespace App\Events\CommandHandler;

use App\Events\Command\StopRequests;
use App\Events\Exception\EventNotFound;
use App\Events\Repository\EventRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\LockMode;
use Psr\Clock\ClockInterface;

final class StopRequestsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(StopRequests $command): void
    {
        // The lock orders this against an in-flight submit, which then either finishes first or sees the new status.
        $event = $this->events->find($command->eventId, LockMode::PESSIMISTIC_WRITE);
        if (null === $event || $event->getAccount()->getId() !== $command->accountId) {
            throw EventNotFound::withId($command->eventId);
        }

        $event->stopRequests($this->clock->now());
    }
}
