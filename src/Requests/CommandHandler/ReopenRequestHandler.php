<?php

declare(strict_types=1);

namespace App\Requests\CommandHandler;

use App\Events\Repository\EventRepository;
use App\Requests\Command\ReopenRequest;
use App\Requests\Entity\SongRequest;
use App\Requests\Exception\EventNotFound;
use App\Requests\Exception\SongRequestNotFound;
use App\Requests\Repository\SongRequestRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\LockMode;
use Psr\Clock\ClockInterface;

final class ReopenRequestHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly SongRequestRepository $songRequests,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(ReopenRequest $command): void
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

        if (SongRequest::STATUS_NEW === $request->getStatus()) {
            return;
        }

        // The song is already back in the queue: reopening would hit uk_requests_event_song, and the old row stays in Done.
        if ($this->songRequests->hasOpenDuplicate($event->getId(), $request->getTitleNormalized(), $request->getArtistNormalized())) {
            return;
        }

        $request->reopen($this->clock->now());
    }
}
