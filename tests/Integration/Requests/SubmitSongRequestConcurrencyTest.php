<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Factory\EventFactory;
use App\Factory\GuestFactory;
use App\Factory\RequestVoteFactory;
use App\Factory\SongRequestFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Fires real concurrent submits (AS-8): each submission runs in its own PHP process with its
 * own kernel and database connection, and all of them dispatch at the same instant.
 *
 * There is no DAMA bundle, so the data seeded here commits and the worker processes see it.
 */
#[ResetDatabase]
final class SubmitSongRequestConcurrencyTest extends KernelTestCase
{
    private const WORKERS = 8;
    private const IP = '203.0.113.7';

    public function test_concurrent_submits_from_one_new_guest_accept_only_one(): void
    {
        $eventId = EventFactory::createOne()->getId();
        $token = Uuid::v4()->toRfc4122();

        $tally = $this->runConcurrently(array_map(
            static fn (int $i): array => [$eventId, $token, 'Song '.$i, null, null],
            range(1, self::WORKERS),
        ));

        self::assertEquals(['accepted' => 1, 'RequestCooldownActive' => self::WORKERS - 1], $tally);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM guests'));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM request_votes'));
    }

    public function test_concurrent_submits_cannot_exceed_the_cap(): void
    {
        $event = EventFactory::createOne();
        $guest = GuestFactory::createOne();
        RequestVoteFactory::createMany(2, ['request' => SongRequestFactory::new(['event' => $event]), 'guest' => $guest]);
        $this->connection()->executeStatement("UPDATE request_votes SET created_at = created_at - interval '10 minutes'");

        $tally = $this->runConcurrently(array_map(
            static fn (int $i): array => [$event->getId(), $guest->getToken(), 'Song '.$i, null, null],
            range(1, self::WORKERS),
        ));

        self::assertEquals(['accepted' => 1, 'GuestRequestLimitReached' => self::WORKERS - 1], $tally);
        self::assertSame(3, (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM request_votes WHERE guest_id = :guest_id',
            ['guest_id' => $guest->getId()],
        ));
    }

    public function test_concurrent_duplicates_merge_into_one_request(): void
    {
        $eventId = EventFactory::createOne()->getId();
        $titles = ['Mr Brightside', 'mr brightside', 'MR BRIGHTSIDE', ' Mr  Brightside', 'mr  BRIGHTSIDE ', 'Mr brightside', '  mr brightside  ', 'MR  brightside'];

        $tally = $this->runConcurrently(array_map(
            static fn (string $title): array => [$eventId, Uuid::v4()->toRfc4122(), $title, null, null],
            $titles,
        ));

        self::assertEquals(['accepted' => self::WORKERS], $tally);
        self::assertSame(
            [['votes' => self::WORKERS]],
            $this->connection()->fetchAllAssociative('SELECT votes FROM requests'),
        );
        self::assertSame(self::WORKERS, (int) $this->connection()->fetchOne('SELECT count(*) FROM request_votes'));
    }

    public function test_concurrent_submits_cannot_exceed_the_ip_backstop(): void
    {
        $event = EventFactory::createOne();
        RequestVoteFactory::createMany(29, [
            'request' => SongRequestFactory::createOne(['event' => $event]),
            'ipAddress' => self::IP,
        ]);

        $tally = $this->runConcurrently(array_map(
            static fn (int $i): array => [$event->getId(), Uuid::v4()->toRfc4122(), 'Song '.$i, null, self::IP],
            range(1, self::WORKERS),
        ));

        self::assertEquals(['accepted' => 1, 'IpRequestLimitReached' => self::WORKERS - 1], $tally);
        self::assertSame(30, (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM request_votes WHERE ip_address = :ip',
            ['ip' => self::IP],
        ));
    }

    /**
     * @param list<array{string, string, string, ?string, ?string}> $submissions eventId, token, title, artist, ip
     *
     * @return array<string, int> how many workers printed each outcome
     */
    private function runConcurrently(array $submissions): array
    {
        // Warms the test container, so the workers only load it.
        self::bootKernel();
        $startAt = microtime(true) + 3.0;

        $processes = [];
        foreach ($submissions as [$eventId, $token, $title, $artist, $ip]) {
            $process = new Process(
                [\PHP_BINARY, __DIR__.'/submit_song_request_worker.php', $eventId, $token, $title, $artist ?? '', $ip ?? '', (string) $startAt],
                env: ['APP_ENV' => 'test'],
                timeout: 30,
            );
            $process->start();
            $processes[] = $process;
        }

        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }

        return array_count_values($outcomes);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
