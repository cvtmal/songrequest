<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Events\Entity\Event;
use App\Factory\EventFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\ConstraintAssertions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SongRequestsConstraintsTest extends KernelTestCase
{
    use ConstraintAssertions;

    public function test_same_song_in_open_queue_is_rejected(): void
    {
        $event = EventFactory::createOne();
        $this->insertRequest($event, 'Mr. Brightside', null);

        self::assertUniqueViolation(
            'uk_requests_event_song',
            fn () => $this->insertRequest($event, "\t mr.  BRIGHTSIDE \n", null),
        );
    }

    public function test_blank_artist_matches_missing_artist(): void
    {
        $event = EventFactory::createOne();
        $this->insertRequest($event, 'Mr. Brightside', '   ');

        self::assertUniqueViolation('uk_requests_event_song', fn () => $this->insertRequest($event, 'Mr. Brightside', null));
    }

    public function test_same_song_in_another_event_is_accepted(): void
    {
        $this->insertRequest(EventFactory::createOne(), 'Mr. Brightside', 'The Killers');
        $this->insertRequest(EventFactory::createOne(), 'Mr. Brightside', 'The Killers');

        self::assertSame(2, $this->countRequests());
    }

    public function test_played_song_can_be_requested_again(): void
    {
        $event = EventFactory::createOne();
        $id = $this->insertRequest($event, 'Mr. Brightside', 'The Killers');
        $this->connection()->update('requests', ['status' => 'played'], ['id' => $id]);

        $this->insertRequest($event, 'Mr. Brightside', 'The Killers');

        self::assertSame(2, $this->countRequests());
    }

    public function test_on_conflict_merge_increments_votes(): void
    {
        $event = EventFactory::createOne();
        $this->insertRequest($event, 'Mr. Brightside', null);

        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO requests (id, account_id, event_id, title, artist)
                VALUES (:id, :account_id, :event_id, :title, :artist)
                ON CONFLICT (event_id, title_normalized, artist_normalized) WHERE status = 'new'
                DO UPDATE SET votes = requests.votes + 1, updated_at = CURRENT_TIMESTAMP
                SQL,
            [
                'id' => EntityId::generate(),
                'account_id' => $event->getAccount()->getId(),
                'event_id' => $event->getId(),
                'title' => 'mr.  brightside ',
                'artist' => null,
            ],
        );

        self::assertSame(1, $this->countRequests());
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT votes FROM requests'));
    }

    public function test_request_status_must_be_known(): void
    {
        $event = EventFactory::createOne();

        self::assertCheckViolation(
            'chk_requests_status',
            fn () => $this->insertRequest($event, 'Mr. Brightside', null, ['status' => 'pending']),
        );
    }

    public function test_votes_must_be_positive(): void
    {
        $event = EventFactory::createOne();

        self::assertCheckViolation(
            'chk_requests_votes',
            fn () => $this->insertRequest($event, 'Mr. Brightside', null, ['votes' => 0]),
        );
    }

    public function test_title_must_not_be_blank(): void
    {
        $event = EventFactory::createOne();

        self::assertCheckViolation('chk_requests_title_not_blank', fn () => $this->insertRequest($event, "  \t ", null));
    }

    /**
     * @param array<string, string|int> $columns
     */
    private function insertRequest(Event $event, string $title, ?string $artist, array $columns = []): string
    {
        $id = EntityId::generate();
        $this->connection()->insert('requests', $columns + [
            'id' => $id,
            'account_id' => $event->getAccount()->getId(),
            'event_id' => $event->getId(),
            'title' => $title,
            'artist' => $artist,
        ]);

        return $id;
    }

    private function countRequests(): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM requests');
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
