<?php

declare(strict_types=1);

namespace App\Tests\Unit\Requests\CommandHandler;

use App\Accounts\Entity\Account;
use App\Events\Entity\Event;
use App\Events\Repository\EventRepository;
use App\Requests\Command\SubmitSongRequest;
use App\Requests\CommandHandler\SubmitSongRequestHandler;
use App\Requests\Entity\Guest;
use App\Requests\Entity\RequestVote;
use App\Requests\Entity\SongRequest;
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
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class SubmitSongRequestHandlerTest extends TestCase
{
    private const NOW = '2026-10-01 22:00:00';
    private const IP = '203.0.113.7';

    private Event $event;
    private EventRepository $events;
    private GuestRepository $guests;
    private AccountGuestBlockRepository $blocks;
    private RequestVoteRepository $votes;
    private SongRequestRepository $songRequests;
    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->event = new Event(new Account('DJ'), 'Party', 'party');

        $this->events = $this->createStub(EventRepository::class);
        $this->events->method('find')->willReturn($this->event);

        $this->guests = $this->createStub(GuestRepository::class);
        $this->guests->method('findOneByToken')->willReturn(null);

        $this->blocks = $this->createStub(AccountGuestBlockRepository::class);
        $this->blocks->method('isBlocked')->willReturn(false);

        $this->votes = $this->createStub(RequestVoteRepository::class);
        $this->votes->method('guestActivityInEvent')->willReturn(['count' => 0, 'lastCreatedAt' => null]);
        $this->votes->method('countByIpSince')->willReturn(0);

        $this->songRequests = $this->createStub(SongRequestRepository::class);
        $this->songRequests->method('addOrVote')->willReturn('request-id');
        $this->songRequests->method('find')->willReturn(new SongRequest($this->event, 'Mr Brightside', null));

        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
    }

    public function test_unknown_event_throws_event_not_found(): void
    {
        $this->events = $this->createStub(EventRepository::class);
        $this->events->method('find')->willReturn(null);

        $this->expectException(EventNotFound::class);

        $this->handle();
    }

    public function test_closed_event_throws_event_closed(): void
    {
        $this->givenEventWithStatus(Event::STATUS_CLOSED);

        $this->expectException(EventClosed::class);

        $this->handle();
    }

    public function test_stopped_event_throws_requests_stopped(): void
    {
        $this->givenEventWithStatus(Event::STATUS_STOPPED);

        $this->expectException(RequestsStopped::class);

        $this->handle();
    }

    public function test_new_guest_is_persisted_and_vote_is_recorded(): void
    {
        $token = Uuid::v4()->toRfc4122();

        $this->handle(token: $token, nickname: 'Anna', ip: self::IP);

        self::assertCount(2, $this->persisted);
        [$guest, $vote] = $this->persisted;
        self::assertInstanceOf(Guest::class, $guest);
        self::assertSame($token, $guest->getToken());
        self::assertInstanceOf(RequestVote::class, $vote);
        self::assertSame($guest, $vote->getGuest());
        self::assertSame('Anna', $vote->getNickname());
        self::assertSame(self::IP, $vote->getIpAddress());
    }

    public function test_muted_guest_is_dropped_silently(): void
    {
        $this->givenExistingGuest();
        $this->blocks = $this->createStub(AccountGuestBlockRepository::class);
        $this->blocks->method('isBlocked')->willReturn(true);
        $songRequests = $this->createMock(SongRequestRepository::class);
        $songRequests->expects(self::never())->method('addOrVote');
        $this->songRequests = $songRequests;

        $this->handle();

        self::assertSame([], $this->persisted);
    }

    public function test_guest_at_cap_throws_limit_reached(): void
    {
        $this->givenGuestActivity(3, null);

        $this->expectException(GuestRequestLimitReached::class);

        $this->handle();
    }

    public function test_cap_is_checked_before_cooldown(): void
    {
        $this->givenGuestActivity(3, new \DateTimeImmutable('2026-10-01 21:59:00 UTC'));

        $this->expectException(GuestRequestLimitReached::class);

        $this->handle();
    }

    public function test_cooldown_throws_with_seconds_left(): void
    {
        $this->givenGuestActivity(1, new \DateTimeImmutable('2026-10-01 21:58:00 UTC'));

        try {
            $this->handle();
            self::fail('Expected RequestCooldownActive.');
        } catch (RequestCooldownActive $e) {
            self::assertSame(180, $e->getSecondsLeft());
        }
    }

    public function test_cooldown_has_passed_after_five_minutes(): void
    {
        $this->givenGuestActivity(1, new \DateTimeImmutable('2026-10-01 21:55:00 UTC'));

        $this->handle();

        self::assertInstanceOf(RequestVote::class, $this->persisted[1] ?? null);
    }

    public function test_ip_backstop_throws_at_limit(): void
    {
        $votes = $this->createMock(RequestVoteRepository::class);
        $votes->method('guestActivityInEvent')->willReturn(['count' => 0, 'lastCreatedAt' => null]);
        $votes->expects(self::once())
            ->method('countByIpSince')
            ->with(
                $this->event->getId(),
                self::IP,
                self::callback(static fn (\DateTimeImmutable $since): bool => '2026-10-01T21:00:00+00:00' === $since->format(\DATE_ATOM)),
            )
            ->willReturn(30);
        $this->votes = $votes;

        $this->expectException(IpRequestLimitReached::class);

        $this->handle(ip: self::IP);
    }

    public function test_ip_backstop_is_skipped_without_ip(): void
    {
        $votes = $this->createMock(RequestVoteRepository::class);
        $votes->method('guestActivityInEvent')->willReturn(['count' => 0, 'lastCreatedAt' => null]);
        $votes->expects(self::never())->method('countByIpSince');
        $this->votes = $votes;

        $this->handle(ip: null);

        self::assertInstanceOf(RequestVote::class, $this->persisted[1] ?? null);
    }

    public function test_own_duplicate_is_a_no_op(): void
    {
        $this->givenExistingGuest();
        $this->songRequests = $this->createStub(SongRequestRepository::class);
        $this->songRequests->method('addOrVote')->willReturn(null);

        $this->handle();

        self::assertSame([], $this->persisted);
    }

    public function test_event_is_loaded_with_a_write_lock(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())
            ->method('find')
            ->with($this->event->getId(), LockMode::PESSIMISTIC_WRITE)
            ->willReturn($this->event);
        $this->events = $events;

        $this->handle();
    }

    private function givenEventWithStatus(string $status): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getId')->willReturn($this->event->getId());
        $event->method('getStatus')->willReturn($status);

        $this->events = $this->createStub(EventRepository::class);
        $this->events->method('find')->willReturn($event);
    }

    private function givenExistingGuest(): void
    {
        $this->guests = $this->createStub(GuestRepository::class);
        $this->guests->method('findOneByToken')->willReturn(new Guest(Uuid::v4()->toRfc4122()));
    }

    private function givenGuestActivity(int $count, ?\DateTimeImmutable $lastCreatedAt): void
    {
        $this->votes = $this->createStub(RequestVoteRepository::class);
        $this->votes->method('guestActivityInEvent')->willReturn(['count' => $count, 'lastCreatedAt' => $lastCreatedAt]);
        $this->votes->method('countByIpSince')->willReturn(0);
    }

    private function handle(?string $token = null, ?string $nickname = null, ?string $ip = self::IP): void
    {
        $handler = new SubmitSongRequestHandler(
            $this->events,
            $this->guests,
            $this->blocks,
            $this->votes,
            $this->songRequests,
            $this->entityManager,
            new MockClock(self::NOW, 'UTC'),
            300,
            3,
            30,
        );

        $handler(new SubmitSongRequest(
            $this->event->getId(),
            $token ?? Uuid::v4()->toRfc4122(),
            'Mr Brightside',
            null,
            $nickname,
            $ip,
        ));
    }
}
