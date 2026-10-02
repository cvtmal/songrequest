<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Events\Entity\Event;
use App\Factory\AccountGuestBlockFactory;
use App\Factory\EventFactory;
use App\Factory\GuestFactory;
use App\Factory\RequestVoteFactory;
use App\Factory\SongRequestFactory;
use App\Requests\Entity\Guest;
use App\Requests\Entity\SongRequest;
use App\Requests\Repository\SongRequestRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SongRequestRepositoryTest extends KernelTestCase
{
    public function test_find_queue_sorts_by_votes_then_first_request(): void
    {
        $event = EventFactory::createOne();
        $this->request($event, 'A', '2026-10-03 21:10:00+00', ['Lea', 'Nico']);
        $this->request($event, 'B', '2026-10-03 21:00:00+00', ['Sam']);
        $this->request($event, 'C', '2026-10-03 21:05:00+00', ['Mia']);

        self::assertSame(['A', 'B', 'C'], array_column($this->findQueue($event), 'title'));
    }

    public function test_find_queue_returns_row_fields(): void
    {
        $event = EventFactory::createOne();
        $request = SongRequestFactory::createOne(['event' => $event, 'title' => 'Dreams', 'artist' => 'Fleetwood Mac']);
        $this->connection()->update('requests', ['created_at' => '2026-10-03 21:00:00+00'], ['id' => $request->getId()]);
        $lea = $this->vote($request, 'Lea', '2026-10-03 21:00:00+00');
        $this->vote($request, 'Nico', '2026-10-03 21:01:00+00');
        $this->vote($request, null, '2026-10-03 21:02:00+00');

        $rows = $this->findQueue($event);

        self::assertCount(1, $rows);
        self::assertSame($request->getId(), $rows[0]['id']);
        self::assertSame('Dreams', $rows[0]['title']);
        self::assertSame('Fleetwood Mac', $rows[0]['artist']);
        self::assertSame(3, $rows[0]['votes']);
        self::assertSame('Lea, Nico', $rows[0]['nicknames']);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 21:00:00+00'), $rows[0]['firstRequestedAt']);
        self::assertSame($lea->getId(), $rows[0]['blockGuestId']);
        self::assertSame('Lea', $rows[0]['blockNickname']);
    }

    public function test_find_queue_ignores_blocked_voters(): void
    {
        $event = EventFactory::createOne();
        $shared = SongRequestFactory::createOne(['event' => $event, 'title' => 'Dreams']);
        $troll = $this->vote($shared, 'Troll', '2026-10-03 21:00:00+00');
        $nico = $this->vote($shared, 'Nico', '2026-10-03 21:01:00+00');
        $alone = SongRequestFactory::createOne(['event' => $event, 'title' => 'Macarena']);
        $this->vote($alone, 'Troll', '2026-10-03 21:02:00+00', $troll);
        AccountGuestBlockFactory::createOne(['account' => $event->getAccount(), 'guest' => $troll]);

        $rows = $this->findQueue($event);

        self::assertSame(['Dreams'], array_column($rows, 'title'));
        self::assertSame(1, $rows[0]['votes']);
        self::assertSame('Nico', $rows[0]['nicknames']);
        self::assertSame($nico->getId(), $rows[0]['blockGuestId']);
    }

    public function test_another_accounts_block_does_not_filter(): void
    {
        $event = EventFactory::createOne();
        $request = SongRequestFactory::createOne(['event' => $event, 'title' => 'Macarena']);
        $lea = $this->vote($request, 'Lea', '2026-10-03 21:00:00+00');
        AccountGuestBlockFactory::createOne(['guest' => $lea]);

        $rows = $this->findQueue($event);

        self::assertSame(['Macarena'], array_column($rows, 'title'));
        self::assertSame(1, $rows[0]['votes']);
    }

    public function test_find_queue_excludes_done_requests_and_other_events(): void
    {
        $event = EventFactory::createOne();
        $this->request($event, 'Open', '2026-10-03 21:00:00+00', ['Lea']);
        $played = $this->request($event, 'Played', '2026-10-03 21:01:00+00', ['Nico']);
        $this->markDone($played, 'played', '2026-10-03 22:00:00+00');
        $this->request(EventFactory::createOne(['account' => $event->getAccount()]), 'Elsewhere', '2026-10-03 21:02:00+00', ['Sam']);

        self::assertSame(['Open'], array_column($this->findQueue($event), 'title'));
    }

    public function test_find_done_lists_played_and_skipped_newest_first(): void
    {
        $event = EventFactory::createOne();
        $played = $this->request($event, 'Played', '2026-10-03 21:00:00+00', ['Lea', 'Nico']);
        $skipped = $this->request($event, 'Skipped', '2026-10-03 21:01:00+00', ['Sam']);
        $this->request($event, 'Open', '2026-10-03 21:02:00+00', ['Mia']);
        $this->markDone($played, 'played', '2026-10-03 22:00:00+00');
        $this->markDone($skipped, 'skipped', '2026-10-03 22:05:00+00');

        $rows = $this->repository()->findDone($event->getId(), $event->getAccount()->getId());

        self::assertSame(['Skipped', 'Played'], array_column($rows, 'title'));
        self::assertSame(['skipped', 'played'], array_column($rows, 'status'));
        self::assertEquals(new \DateTimeImmutable('2026-10-03 22:05:00+00'), $rows[0]['handledAt']);
        self::assertSame(2, $rows[1]['votes']);
        self::assertSame('Lea, Nico', $rows[1]['nicknames']);
    }

    public function test_count_done(): void
    {
        $event = EventFactory::createOne();
        $this->markDone($this->request($event, 'Played', '2026-10-03 21:00:00+00', ['Lea']), 'played', '2026-10-03 22:00:00+00');
        $this->markDone($this->request($event, 'Skipped', '2026-10-03 21:01:00+00', ['Nico']), 'skipped', '2026-10-03 22:01:00+00');
        $this->request($event, 'Open', '2026-10-03 21:02:00+00', ['Sam']);
        $other = EventFactory::createOne(['account' => $event->getAccount()]);

        self::assertSame(2, $this->repository()->countDone($event->getId(), $event->getAccount()->getId()));
        self::assertSame(0, $this->repository()->countDone($other->getId(), $event->getAccount()->getId()));
    }

    public function test_has_open_duplicate(): void
    {
        $event = EventFactory::createOne();
        $request = SongRequestFactory::createOne(['event' => $event, 'title' => 'Dreams', 'artist' => null]);
        $title = $this->connection()->fetchOne('SELECT title_normalized FROM requests WHERE id = :id', ['id' => $request->getId()]);
        self::assertIsString($title);

        self::assertTrue($this->repository()->hasOpenDuplicate($event->getId(), $title, null));
        self::assertFalse($this->repository()->hasOpenDuplicate($event->getId(), $title, 'fleetwood mac'));

        $this->markDone($request, 'played', '2026-10-03 22:00:00+00');

        self::assertFalse($this->repository()->hasOpenDuplicate($event->getId(), $title, null));
    }

    /**
     * @param list<string|null> $nicknames one vote per nickname, a minute apart from $createdAt
     */
    private function request(Event $event, string $title, string $createdAt, array $nicknames): SongRequest
    {
        $request = SongRequestFactory::createOne(['event' => $event, 'title' => $title]);
        $this->connection()->update('requests', ['created_at' => $createdAt], ['id' => $request->getId()]);

        foreach ($nicknames as $i => $nickname) {
            $this->vote($request, $nickname, new \DateTimeImmutable($createdAt)->modify(\sprintf('+%d minutes', $i))->format('Y-m-d H:i:sP'));
        }

        return $request;
    }

    private function vote(SongRequest $request, ?string $nickname, string $createdAt, ?Guest $guest = null): Guest
    {
        $guest ??= GuestFactory::createOne();
        $vote = RequestVoteFactory::createOne(['request' => $request, 'guest' => $guest, 'nickname' => $nickname]);
        $this->connection()->update('request_votes', ['created_at' => $createdAt], ['id' => $vote->getId()]);

        return $guest;
    }

    private function markDone(SongRequest $request, string $status, string $handledAt): void
    {
        $this->connection()->update('requests', ['status' => $status, 'handled_at' => $handledAt], ['id' => $request->getId()]);
    }

    /**
     * @return list<array{id: string, title: string, artist: ?string, votes: int, nicknames: ?string, firstRequestedAt: \DateTimeImmutable, blockGuestId: string, blockNickname: ?string}>
     */
    private function findQueue(Event $event): array
    {
        return $this->repository()->findQueue($event->getId(), $event->getAccount()->getId());
    }

    private function repository(): SongRequestRepository
    {
        $repository = self::getContainer()->get(SongRequestRepository::class);
        self::assertInstanceOf(SongRequestRepository::class, $repository);

        return $repository;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
