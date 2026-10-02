<?php

declare(strict_types=1);

namespace App\Tests\Integration\Events;

use App\Events\Command\CreateEvent;
use App\Factory\AccountFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreateEventHandlerTest extends KernelTestCase
{
    public function test_dispatch_returns_the_new_id_through_handled_stamp(): void
    {
        $account = AccountFactory::createOne();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $envelope = $bus->dispatch(new CreateEvent($account->getId(), 'Plaza Club'));

        $id = $envelope->last(HandledStamp::class)?->getResult();
        self::assertIsString($id);
        $row = $this->connection()->fetchAssociative('SELECT * FROM events WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertSame('Plaza Club', $row['name']);
        self::assertSame('open', $row['status']);
        self::assertSame($account->getId(), $row['account_id']);
        self::assertNotNull($row['opened_at']);
        self::assertNull($row['closed_at']);
        self::assertIsString($row['slug']);
        self::assertMatchesRegularExpression('/^[2-9a-hjkmnp-z]{10}$/', $row['slug']);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
