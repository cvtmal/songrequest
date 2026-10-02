<?php

declare(strict_types=1);

namespace App\Requests\CommandHandler;

use App\Events\Entity\Event;
use App\Events\Repository\EventRepository;
use App\Requests\Command\SubmitSongRequest;
use App\Requests\Entity\Guest;
use App\Requests\Entity\RequestVote;
use App\Requests\Exception\EventClosed;
use App\Requests\Exception\EventNotFound;
use App\Requests\Exception\GuestRequestLimitReached;
use App\Requests\Exception\IpRequestLimitReached;
use App\Requests\Exception\RequestCooldownActive;
use App\Requests\Exception\RequestsStopped;
use App\Requests\Repository\AccountGuestBlockRepository;
use App\Requests\Repository\GuestRepository;
use App\Requests\Repository\RequestVoteRepository;
use App\Requests\Repository\SongRequestRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SubmitSongRequestHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly GuestRepository $guests,
        private readonly AccountGuestBlockRepository $blocks,
        private readonly RequestVoteRepository $votes,
        private readonly SongRequestRepository $songRequests,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'requests.cooldown_seconds')]
        private readonly int $cooldownSeconds,
        #[Autowire(param: 'requests.max_per_guest')]
        private readonly int $maxPerGuest,
        #[Autowire(param: 'requests.max_per_ip_per_hour')]
        private readonly int $maxPerIpPerHour,
    ) {
    }

    public function __invoke(SubmitSongRequest $command): void
    {
        // The row lock serialises submits per event, so the counts below see every earlier accepted vote (AS-8).
        $event = $this->events->find($command->eventId, LockMode::PESSIMISTIC_WRITE);
        if (null === $event) {
            throw EventNotFound::withId($command->eventId);
        }

        if (Event::STATUS_CLOSED === $event->getStatus()) {
            throw EventClosed::forEvent($event->getId());
        }
        if (Event::STATUS_STOPPED === $event->getStatus()) {
            throw RequestsStopped::forEvent($event->getId());
        }

        $guest = $this->guests->findOneByToken($command->guestToken);
        $isNewGuest = null === $guest;
        if (null === $guest) {
            $guest = new Guest($command->guestToken);
            $this->entityManager->persist($guest);
        }

        // AS-6: a muted guest sees success, and nothing is stored or counted.
        if (!$isNewGuest && $this->blocks->isBlocked($event->getAccount()->getId(), $guest->getId())) {
            return;
        }

        $now = $this->clock->now();

        // The cap is permanent, so it wins over a countdown the guest could never use.
        $activity = $this->votes->guestActivityInEvent($event->getId(), $guest->getId());
        if ($activity['count'] >= $this->maxPerGuest) {
            throw GuestRequestLimitReached::forEvent($event->getId());
        }
        if (null !== $activity['lastCreatedAt']) {
            $secondsLeft = (int) ceil(($activity['lastCreatedAt']->getTimestamp() + $this->cooldownSeconds) - (float) $now->format('U.u'));
            if ($secondsLeft > 0) {
                throw RequestCooldownActive::forEvent($event->getId(), $secondsLeft);
            }
        }

        if (null !== $command->ipAddress
            && $this->votes->countByIpSince($event->getId(), $command->ipAddress, $now->modify('-1 hour')) >= $this->maxPerIpPerHour
        ) {
            throw IpRequestLimitReached::forEvent($event->getId());
        }

        $requestId = $this->songRequests->addOrVote(
            $event->getId(),
            $event->getAccount()->getId(),
            $guest->getId(),
            $command->title,
            $command->artist,
        );
        // The guest already voted for this open song: a no-op success.
        if (null === $requestId) {
            return;
        }

        $songRequest = $this->songRequests->find($requestId);
        \assert(null !== $songRequest);

        $this->entityManager->persist(new RequestVote($songRequest, $guest, $command->nickname, $command->ipAddress));
    }
}
