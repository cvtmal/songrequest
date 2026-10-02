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
        // No method sets stopped until #6.
        (new \ReflectionProperty(Event::class, 'status'))->setValue($event, Event::STATUS_STOPPED);

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

    private function event(): Event
    {
        return new Event(new Account('DJ Mira'), 'Plaza Club', 'k7m3qz9xwa');
    }
}
