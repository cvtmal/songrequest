<?php

declare(strict_types=1);

namespace App\Tests\Integration\Events;

use App\Factory\AccountFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\ConstraintAssertions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class EventsConstraintsTest extends KernelTestCase
{
    use ConstraintAssertions;

    public function test_event_slug_is_unique(): void
    {
        $this->insertEvent(['slug' => 'demo']);

        self::assertUniqueViolation('uk_events_slug', fn () => $this->insertEvent(['slug' => 'demo']));
    }

    public function test_event_status_must_be_known(): void
    {
        self::assertCheckViolation('chk_events_status', fn () => $this->insertEvent(['status' => 'draft']));
    }

    public function test_closed_event_requires_closed_at(): void
    {
        self::assertCheckViolation('chk_events_closed_at', fn () => $this->insertEvent(['status' => 'closed']));
    }

    public function test_open_event_must_not_have_closed_at(): void
    {
        self::assertCheckViolation(
            'chk_events_closed_at',
            fn () => $this->insertEvent(['status' => 'open', 'closed_at' => '2026-10-01 23:00:00+00']),
        );
    }

    public function test_closed_event_with_closed_at_is_accepted(): void
    {
        $this->insertEvent(['status' => 'closed', 'closed_at' => '2026-10-01 23:00:00+00']);

        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT count(*) FROM events WHERE status = 'closed'"));
    }

    /**
     * @param array<string, string> $columns
     */
    private function insertEvent(array $columns): void
    {
        $this->connection()->insert('events', $columns + [
            'id' => EntityId::generate(),
            'account_id' => AccountFactory::createOne()->getId(),
            'name' => 'Test Night',
            'slug' => 'event-'.bin2hex(random_bytes(4)),
            'status' => 'open',
        ]);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
