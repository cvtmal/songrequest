<?php

declare(strict_types=1);

namespace App\Tests\Integration\Events;

use App\Events\Repository\EventRepository;
use App\Factory\AccountFactory;
use App\Factory\EventFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class EventRepositoryTest extends KernelTestCase
{
    public function test_find_one_by_slug_returns_event_with_account(): void
    {
        $event = EventFactory::createOne(['slug' => 'summer-party']);

        $found = $this->repository()->findOneBySlug('summer-party');

        self::assertNotNull($found);
        self::assertSame($event->getId(), $found->getId());
        self::assertSame($event->getAccount()->getStageName(), $found->getAccount()->getStageName());
    }

    public function test_find_one_by_slug_returns_null_for_unknown_slug(): void
    {
        EventFactory::createOne(['slug' => 'summer-party']);

        self::assertNull($this->repository()->findOneBySlug('nope'));
    }

    public function test_find_one_for_account_returns_own_event(): void
    {
        $event = EventFactory::createOne();

        $found = $this->repository()->findOneForAccount($event->getId(), $event->getAccount());

        self::assertNotNull($found);
        self::assertSame($event->getId(), $found->getId());
    }

    public function test_find_one_for_account_returns_null_for_another_accounts_event(): void
    {
        $event = EventFactory::createOne();
        $other = AccountFactory::createOne();

        self::assertNull($this->repository()->findOneForAccount($event->getId(), $other));
    }

    public function test_find_for_account_returns_only_own_events_newest_first(): void
    {
        $account = AccountFactory::createOne();
        $oldest = EventFactory::createOne(['account' => $account]);
        $newest = EventFactory::createOne(['account' => $account]);
        $middle = EventFactory::createOne(['account' => $account]);
        $foreign = EventFactory::createOne();
        foreach ([
            [$oldest->getId(), '2026-10-01 20:00:00+00'],
            [$middle->getId(), '2026-10-01 21:00:00+00'],
            [$newest->getId(), '2026-10-01 22:00:00+00'],
        ] as [$id, $createdAt]) {
            $this->connection()->executeStatement('UPDATE events SET created_at = :t WHERE id = :id', ['t' => $createdAt, 'id' => $id]);
        }
        $this->entityManager()->clear();

        $account = $this->entityManager()->find($account::class, $account->getId());
        self::assertNotNull($account);
        $ids = array_map(static fn ($event) => $event->getId(), $this->repository()->findForAccount($account));

        self::assertSame([$newest->getId(), $middle->getId(), $oldest->getId()], $ids);
        self::assertNotContains($foreign->getId(), $ids);
    }

    private function repository(): EventRepository
    {
        $repository = self::getContainer()->get(EventRepository::class);
        self::assertInstanceOf(EventRepository::class, $repository);

        return $repository;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
