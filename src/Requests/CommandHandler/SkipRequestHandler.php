<?php

declare(strict_types=1);

namespace App\Requests\CommandHandler;

use App\Events\Repository\EventRepository;
use App\Requests\Command\SkipRequest;
use App\Requests\Exception\EventNotFound;
use App\Requests\Exception\SongRequestNotFound;
use App\Requests\Repository\SongRequestRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\LockMode;
use Psr\Clock\ClockInterface;

final class SkipRequestHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly SongRequestRepository $songRequests,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SkipRequest $command): void
    {
        // The lock serialises queue changes with submits, so a merge into this request cannot interleave with the status change.
        $event = $this->events->find($command->eventId, LockMode::PESSIMISTIC_WRITE);
        if (null === $event || $event->getAccount()->getId() !== $command->accountId) {
            throw EventNotFound::withId($command->eventId);
        }

        $request = $this->songRequests->findOneBy(['id' => $command->requestId, 'event' => $event]);
        if (null === $request) {
            throw SongRequestNotFound::withId($command->requestId);
        }

        $request->markSkipped($this->clock->now());
    }
}
