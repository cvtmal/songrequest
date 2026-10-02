<?php

declare(strict_types=1);

namespace App\Events\CommandHandler;

use App\Events\Command\CloseEvent;
use App\Events\Exception\EventNotFound;
use App\Events\Repository\EventRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\LockMode;
use Psr\Clock\ClockInterface;

final class CloseEventHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CloseEvent $command): void
    {
        // The row lock orders this close against an in-flight submit, which then either finishes first or sees closed.
        $event = $this->events->find($command->eventId, LockMode::PESSIMISTIC_WRITE);
        if (null === $event || $event->getAccount()->getId() !== $command->accountId) {
            throw EventNotFound::withId($command->eventId);
        }

        $event->close($this->clock->now());
    }
}
