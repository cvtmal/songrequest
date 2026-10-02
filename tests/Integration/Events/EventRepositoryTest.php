<?php

declare(strict_types=1);

namespace App\Tests\Integration\Events;

use App\Events\Repository\EventRepository;
use App\Factory\EventFactory;
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

    private function repository(): EventRepository
    {
        $repository = self::getContainer()->get(EventRepository::class);
        self::assertInstanceOf(EventRepository::class, $repository);

        return $repository;
    }
}
