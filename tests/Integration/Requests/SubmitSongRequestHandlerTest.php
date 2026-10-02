<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Events\Entity\Event;
use App\Factory\AccountGuestBlockFactory;
use App\Factory\EventFactory;
use App\Factory\GuestFactory;
use App\Factory\RequestVoteFactory;
use App\Factory\SongRequestFactory;
use App\Requests\Command\SubmitSongRequest;
use App\Requests\Exception\EventClosed;
use App\Requests\Exception\GuestRequestLimitReached;
use App\Requests\Exception\IpRequestLimitReached;
use App\Requests\Exception\RequestCooldownActive;
use App\Requests\Exception\RequestsStopped;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SubmitSongRequestHandlerTest extends KernelTestCase
{
    private const IP = '203.0.113.7';

    public function test_first_submit_creates_guest_request_and_vote(): void
    {
        $event = EventFactory::createOne();
        $token = Uuid::v4()->toRfc4122();

        $this->submit($event->getId(), $token, 'Mr Brightside', 'The Killers', self::IP, 'Anna');

        self::assertSame($token, $this->connection()->fetchOne('SELECT token FROM guests'));
        self::assertSame(1, $this->countRows('requests'));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT votes FROM requests'));
        self::assertSame(
            [['nickname' => 'Anna', 'ip_address' => self::IP]],
            $this->connection()->fetchAllAssociative('SELECT nickname, ip_address FROM request_votes'),
        );
    }

    public function test_duplicate_from_another_guest_merges_into_one_request(): void
    {
        $event = EventFactory::createOne();

        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), '  Mr  Brightside ');
        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'MR BRIGHTSIDE');

        self::assertSame(1, $this->countRows('requests'));
        // Also guards against doctrine/orm#12017: a phantom UPDATE of the loaded request would reset votes.
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT votes FROM requests'));
        self::assertSame(2, $this->countRows('request_votes'));
    }

    public function test_guest_re_requesting_own_song_is_a_no_op(): void
    {
        $event = EventFactory::createOne();
        $token = Uuid::v4()->toRfc4122();
        $this->submit($event->getId(), $token, 'Mr Brightside');
        $this->backdateVotes(10);

        $this->submit($event->getId(), $token, 'mr brightside');

        self::assertSame(1, $this->countRows('requests'));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT votes FROM requests'));
        self::assertSame(1, $this->countRows('request_votes'));
    }

    public function test_played_song_requested_again_creates_a_new_request(): void
    {
        $event = EventFactory::createOne();
        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside');
        $this->connection()->executeStatement("UPDATE requests SET status = 'played', handled_at = CURRENT_TIMESTAMP");

        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside');

        self::assertSame(2, $this->countRows('requests'));
    }

    public function test_duplicate_counts_against_the_cap(): void
    {
        $event = EventFactory::createOne();
        $guest = GuestFactory::createOne();
        RequestVoteFactory::createMany(2, ['request' => SongRequestFactory::new(['event' => $event]), 'guest' => $guest]);
        $this->backdateVotes(10);
        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside');

        $this->submit($event->getId(), $guest->getToken(), 'mr brightside');
        self::assertSame(3, $this->countVotesOf($guest->getToken()));

        $this->expectException(GuestRequestLimitReached::class);
        $this->submit($event->getId(), $guest->getToken(), 'Somebody Told Me');
    }

    public function test_cooldown_blocks_a_second_submit_within_five_minutes(): void
    {
        $event = EventFactory::createOne();
        $token = Uuid::v4()->toRfc4122();
        $this->submit($event->getId(), $token, 'Mr Brightside');

        $this->expectException(RequestCooldownActive::class);
        $this->submit($event->getId(), $token, 'Somebody Told Me');
    }

    public function test_submit_is_accepted_after_the_cooldown(): void
    {
        $event = EventFactory::createOne();
        $token = Uuid::v4()->toRfc4122();
        $this->submit($event->getId(), $token, 'Mr Brightside');
        $this->backdateVotes(6);

        $this->submit($event->getId(), $token, 'Somebody Told Me');

        self::assertSame(2, $this->countVotesOf($token));
    }

    public function test_ip_backstop_rejects_the_31st_request_in_an_hour(): void
    {
        $event = EventFactory::createOne();
        $this->seedVotesFromIp($event);

        $this->expectException(IpRequestLimitReached::class);
        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside', ip: self::IP);
    }

    public function test_ip_votes_older_than_an_hour_do_not_count(): void
    {
        $event = EventFactory::createOne();
        $this->seedVotesFromIp($event);
        $this->backdateVotes(61);

        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside', ip: self::IP);

        self::assertSame(31, $this->countRows('request_votes'));
    }

    public function test_muted_guest_is_dropped_silently(): void
    {
        $event = EventFactory::createOne();
        $guest = GuestFactory::createOne();
        AccountGuestBlockFactory::createOne(['account' => $event->getAccount(), 'guest' => $guest]);

        $this->submit($event->getId(), $guest->getToken(), 'Mr Brightside');

        self::assertSame(0, $this->countRows('requests'));
        self::assertSame(0, $this->countRows('request_votes'));
    }

    public function test_closed_event_rejects_submit(): void
    {
        $event = EventFactory::createOne();
        $this->connection()->executeStatement(
            "UPDATE events SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $event->getId()],
        );

        $this->expectException(EventClosed::class);
        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside');
    }

    public function test_stopped_event_rejects_submit(): void
    {
        $event = EventFactory::createOne();
        $this->connection()->update('events', ['status' => Event::STATUS_STOPPED], ['id' => $event->getId()]);

        $this->expectException(RequestsStopped::class);
        $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside');
    }

    public function test_rejected_submit_rolls_back_new_guest(): void
    {
        $event = EventFactory::createOne();
        $this->seedVotesFromIp($event);
        $guests = $this->countRows('guests');

        try {
            // The new guest is persisted before the IP backstop rejects the submit.
            $this->submit($event->getId(), Uuid::v4()->toRfc4122(), 'Mr Brightside', ip: self::IP);
            self::fail('Expected IpRequestLimitReached.');
        } catch (IpRequestLimitReached) {
        }

        self::assertSame($guests, $this->countRows('guests'));
    }

    private function submit(
        string $eventId,
        string $token,
        string $title,
        ?string $artist = null,
        ?string $ip = null,
        ?string $nickname = null,
    ): void {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        try {
            $bus->dispatch(new SubmitSongRequest($eventId, $token, $title, $artist, $nickname, $ip));
        } catch (HandlerFailedException $e) {
            throw $e->getWrappedExceptions()[array_key_first($e->getWrappedExceptions())];
        }
    }

    /**
     * 30 accepted votes from one IP in the last hour, each from its own guest.
     */
    private function seedVotesFromIp(Event $event): void
    {
        RequestVoteFactory::createMany(30, [
            'request' => SongRequestFactory::createOne(['event' => $event]),
            'ipAddress' => self::IP,
        ]);
    }

    private function backdateVotes(int $minutes): void
    {
        $this->connection()->executeStatement(
            'UPDATE request_votes SET created_at = created_at - make_interval(mins => :minutes)',
            ['minutes' => $minutes],
        );
    }

    private function countVotesOf(string $token): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM request_votes v JOIN guests g ON g.id = v.guest_id WHERE g.token = :token',
            ['token' => $token],
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
