<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Events\Entity\Event;
use App\Factory\EventFactory;
use App\Requests\Command\MarkRequestPlayed;
use App\Requests\Command\ReopenRequest;
use App\Requests\Command\SkipRequest;
use App\Requests\Command\SubmitSongRequest;
use App\Requests\Exception\EventNotFound;
use App\Requests\Exception\SongRequestNotFound;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class RequestStatusHandlersTest extends KernelTestCase
{
    public function test_mark_played_sets_status_and_handled_at(): void
    {
        $event = EventFactory::createOne();
        $id = $this->submit($event, 'Dreams');

        $this->dispatch(new MarkRequestPlayed($id, $event->getId(), $event->getAccount()->getId()));

        $row = $this->row($id);
        self::assertSame('played', $row['status']);
        self::assertNotNull($row['handled_at']);
    }

    public function test_skip_sets_status_skipped(): void
    {
        $event = EventFactory::createOne();
        $id = $this->submit($event, 'Dreams');

        $this->dispatch(new SkipRequest($id, $event->getId(), $event->getAccount()->getId()));

        self::assertSame('skipped', $this->row($id)['status']);
    }

    public function test_reopen_moves_a_done_request_back_to_the_queue(): void
    {
        $event = EventFactory::createOne();
        $id = $this->submit($event, 'Dreams');
        $this->dispatch(new MarkRequestPlayed($id, $event->getId(), $event->getAccount()->getId()));

        $this->dispatch(new ReopenRequest($id, $event->getId(), $event->getAccount()->getId()));

        $row = $this->row($id);
        self::assertSame('new', $row['status']);
        self::assertNull($row['handled_at']);
    }

    public function test_reopen_is_a_no_op_when_the_song_is_open_again(): void
    {
        $event = EventFactory::createOne();
        $id = $this->submit($event, 'Dreams');
        $this->dispatch(new MarkRequestPlayed($id, $event->getId(), $event->getAccount()->getId()));
        $this->submit($event, 'dreams ');

        $this->dispatch(new ReopenRequest($id, $event->getId(), $event->getAccount()->getId()));

        self::assertSame('played', $this->row($id)['status']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT count(*) FROM requests WHERE status = 'new'"));
    }

    public function test_actions_work_on_a_closed_event(): void
    {
        $event = EventFactory::createOne();
        $id = $this->submit($event, 'Dreams');
        $this->connection()->executeStatement(
            "UPDATE events SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $event->getId()],
        );

        $this->dispatch(new MarkRequestPlayed($id, $event->getId(), $event->getAccount()->getId()));

        self::assertSame('played', $this->row($id)['status']);
    }

    public function test_other_accounts_event_is_not_found(): void
    {
        $event = EventFactory::createOne();
        $theirs = EventFactory::createOne();
        $id = $this->submit($theirs, 'Dreams');

        $this->expectException(EventNotFound::class);

        $this->dispatch(new MarkRequestPlayed($id, $theirs->getId(), $event->getAccount()->getId()));
    }

    public function test_request_from_another_event_is_not_found(): void
    {
        $event = EventFactory::createOne();
        $other = EventFactory::createOne(['account' => $event->getAccount()]);
        $id = $this->submit($other, 'Dreams');

        $this->expectException(SongRequestNotFound::class);

        $this->dispatch(new MarkRequestPlayed($id, $event->getId(), $event->getAccount()->getId()));
    }

    private function submit(Event $event, string $title): string
    {
        $this->dispatch(new SubmitSongRequest($event->getId(), Uuid::v4()->toRfc4122(), $title, null, null, null));

        $id = $this->connection()->fetchOne(
            "SELECT id FROM requests WHERE event_id = :event_id AND status = 'new' ORDER BY created_at DESC, id DESC LIMIT 1",
            ['event_id' => $event->getId()],
        );
        self::assertIsString($id);

        return $id;
    }

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        try {
            $bus->dispatch($command);
        } catch (HandlerFailedException $e) {
            throw $e->getWrappedExceptions()[array_key_first($e->getWrappedExceptions())];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, handled_at FROM requests WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
