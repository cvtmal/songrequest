<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events\Entity;

use App\Accounts\Entity\Account;
use App\Events\Entity\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function test_new_event_is_open(): void
    {
        $event = $this->event();

        self::assertSame(Event::STATUS_OPEN, $event->getStatus());
        self::assertNotNull($event->getOpenedAt());
        self::assertNull($event->getClosedAt());
    }

    public function test_close_sets_status_closed_at_and_updated_at(): void
    {
        $event = $this->event();
        $now = new \DateTimeImmutable('2026-10-03 03:00:00');

        $event->close($now);

        self::assertSame(Event::STATUS_CLOSED, $event->getStatus());
        self::assertEquals($now, $event->getClosedAt());
        self::assertEquals($now, $event->getUpdatedAt());
    }

    public function test_close_works_from_stopped(): void
    {
        $event = $this->event();
        $event->stopRequests(new \DateTimeImmutable('2026-10-03 02:00:00'));

        $event->close(new \DateTimeImmutable('2026-10-03 03:00:00'));

        self::assertSame(Event::STATUS_CLOSED, $event->getStatus());
    }

    public function test_closing_twice_keeps_the_first_closed_at(): void
    {
        $event = $this->event();
        $first = new \DateTimeImmutable('2026-10-03 03:00:00');

        $event->close($first);
        $event->close(new \DateTimeImmutable('2026-10-03 04:00:00'));

        self::assertEquals($first, $event->getClosedAt());
        self::assertEquals($first, $event->getUpdatedAt());
    }

    public function test_stop_requests_sets_stopped_and_updated_at(): void
    {
        $event = $this->event();
        $now = new \DateTimeImmutable('2026-10-03 02:00:00');

        $event->stopRequests($now);

        self::assertSame(Event::STATUS_STOPPED, $event->getStatus());
        self::assertEquals($now, $event->getUpdatedAt());
    }

    public function test_stopping_twice_is_a_no_op(): void
    {
        $event = $this->event();
        $first = new \DateTimeImmutable('2026-10-03 02:00:00');

        $event->stopRequests($first);
        $event->stopRequests(new \DateTimeImmutable('2026-10-03 02:30:00'));

        self::assertSame(Event::STATUS_STOPPED, $event->getStatus());
        self::assertEquals($first, $event->getUpdatedAt());
    }

    public function test_resume_requests_reopens_a_stopped_event(): void
    {
        $event = $this->event();
        $openedAt = $event->getOpenedAt();
        $now = new \DateTimeImmutable('2026-10-03 02:30:00');
        $event->stopRequests(new \DateTimeImmutable('2026-10-03 02:00:00'));

        $event->resumeRequests($now);

        self::assertSame(Event::STATUS_OPEN, $event->getStatus());
        self::assertEquals($now, $event->getUpdatedAt());
        self::assertSame($openedAt, $event->getOpenedAt());
    }

    public function test_resume_of_an_open_event_is_a_no_op(): void
    {
        $event = $this->event();
        $updatedAt = $event->getUpdatedAt();

        $event->resumeRequests(new \DateTimeImmutable('2026-10-03 02:30:00'));

        self::assertSame(Event::STATUS_OPEN, $event->getStatus());
        self::assertSame($updatedAt, $event->getUpdatedAt());
    }

    public function test_stop_and_resume_do_not_leave_closed(): void
    {
        $event = $this->event();
        $closedAt = new \DateTimeImmutable('2026-10-03 03:00:00');
        $event->close($closedAt);

        $event->stopRequests(new \DateTimeImmutable('2026-10-03 03:10:00'));
        $event->resumeRequests(new \DateTimeImmutable('2026-10-03 03:20:00'));

        self::assertSame(Event::STATUS_CLOSED, $event->getStatus());
        self::assertEquals($closedAt, $event->getClosedAt());
        self::assertEquals($closedAt, $event->getUpdatedAt());
    }

    private function event(): Event
    {
        return new Event(new Account('DJ Mira'), 'Plaza Club', 'k7m3qz9xwa');
    }
}
