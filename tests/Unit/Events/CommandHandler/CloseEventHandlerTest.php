<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events\CommandHandler;

use App\Accounts\Entity\Account;
use App\Events\Command\CloseEvent;
use App\Events\CommandHandler\CloseEventHandler;
use App\Events\Entity\Event;
use App\Events\Exception\EventNotFound;
use App\Events\Repository\EventRepository;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\LockMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CloseEventHandlerTest extends TestCase
{
    private const NOW = '2026-10-03 03:00:00';

    private Event $event;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->event = new Event(new Account('DJ Mira'), 'Plaza Club', 'k7m3qz9xwa');
        $this->clock = new MockClock(self::NOW);
    }

    public function test_closes_the_event_at_clock_time(): void
    {
        $this->handle($this->repositoryReturning($this->event));

        self::assertSame(Event::STATUS_CLOSED, $this->event->getStatus());
        self::assertEquals($this->clock->now(), $this->event->getClosedAt());
    }

    public function test_missing_event_throws_event_not_found(): void
    {
        $this->expectException(EventNotFound::class);

        $this->handle($this->repositoryReturning(null));
    }

    public function test_other_accounts_event_throws_event_not_found(): void
    {
        try {
            $this->handle($this->repositoryReturning($this->event), accountId: EntityId::generate());
            self::fail('Expected EventNotFound.');
        } catch (EventNotFound) {
        }

        self::assertSame(Event::STATUS_OPEN, $this->event->getStatus());
    }

    public function test_closing_a_closed_event_is_a_no_op(): void
    {
        $earlier = new \DateTimeImmutable('2026-10-03 01:00:00');
        $this->event->close($earlier);

        $this->handle($this->repositoryReturning($this->event));

        self::assertSame(Event::STATUS_CLOSED, $this->event->getStatus());
        self::assertEquals($earlier, $this->event->getClosedAt());
    }

    public function test_loads_the_event_with_a_write_lock(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())
            ->method('find')
            ->with($this->event->getId(), LockMode::PESSIMISTIC_WRITE)
            ->willReturn($this->event);

        $this->handle($events);
    }

    private function repositoryReturning(?Event $event): EventRepository
    {
        $events = $this->createStub(EventRepository::class);
        $events->method('find')->willReturn($event);

        return $events;
    }

    private function handle(EventRepository $events, ?string $accountId = null): void
    {
        $handler = new CloseEventHandler($events, $this->clock);

        $handler(new CloseEvent($this->event->getId(), $accountId ?? $this->event->getAccount()->getId()));
    }
}
