<?php

declare(strict_types=1);

namespace App\Tests\Unit\Requests\Entity;

use App\Accounts\Entity\Account;
use App\Events\Entity\Event;
use App\Requests\Entity\SongRequest;
use PHPUnit\Framework\TestCase;

final class SongRequestTest extends TestCase
{
    public function test_new_request_is_open_without_handled_at(): void
    {
        $request = $this->request();

        self::assertSame(SongRequest::STATUS_NEW, $request->getStatus());
        self::assertNull($request->getHandledAt());
    }

    public function test_mark_played_sets_status_handled_at_and_updated_at(): void
    {
        $request = $this->request();
        $now = new \DateTimeImmutable('2026-10-03 01:00:00');

        $request->markPlayed($now);

        self::assertSame(SongRequest::STATUS_PLAYED, $request->getStatus());
        self::assertEquals($now, $request->getHandledAt());
        self::assertEquals($now, $request->getUpdatedAt());
    }

    public function test_mark_skipped_sets_status_and_handled_at(): void
    {
        $request = $this->request();
        $now = new \DateTimeImmutable('2026-10-03 01:00:00');

        $request->markSkipped($now);

        self::assertSame(SongRequest::STATUS_SKIPPED, $request->getStatus());
        self::assertEquals($now, $request->getHandledAt());
    }

    public function test_played_can_switch_to_skipped(): void
    {
        $request = $this->request();
        $later = new \DateTimeImmutable('2026-10-03 01:05:00');
        $request->markPlayed(new \DateTimeImmutable('2026-10-03 01:00:00'));

        $request->markSkipped($later);

        self::assertSame(SongRequest::STATUS_SKIPPED, $request->getStatus());
        self::assertEquals($later, $request->getHandledAt());
    }

    public function test_marking_played_twice_keeps_the_first_handled_at(): void
    {
        $request = $this->request();
        $first = new \DateTimeImmutable('2026-10-03 01:00:00');

        $request->markPlayed($first);
        $request->markPlayed(new \DateTimeImmutable('2026-10-03 01:05:00'));

        self::assertEquals($first, $request->getHandledAt());
        self::assertEquals($first, $request->getUpdatedAt());
    }

    public function test_reopen_clears_handled_at(): void
    {
        $request = $this->request();
        $now = new \DateTimeImmutable('2026-10-03 01:10:00');
        $request->markPlayed(new \DateTimeImmutable('2026-10-03 01:00:00'));

        $request->reopen($now);

        self::assertSame(SongRequest::STATUS_NEW, $request->getStatus());
        self::assertNull($request->getHandledAt());
        self::assertEquals($now, $request->getUpdatedAt());
    }

    public function test_reopen_of_an_open_request_is_a_no_op(): void
    {
        $request = $this->request();
        $updatedAt = $request->getUpdatedAt();

        $request->reopen(new \DateTimeImmutable('2026-10-03 01:10:00'));

        self::assertSame(SongRequest::STATUS_NEW, $request->getStatus());
        self::assertSame($updatedAt, $request->getUpdatedAt());
    }

    private function request(): SongRequest
    {
        return new SongRequest(new Event(new Account('DJ Mira'), 'Plaza Club', 'k7m3qz9xwa'), 'Dreams', 'Fleetwood Mac');
    }
}
