<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events\CommandHandler;

use App\Accounts\Entity\Account;
use App\Events\Command\CreateEvent;
use App\Events\CommandHandler\CreateEventHandler;
use App\Events\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CreateEventHandlerTest extends TestCase
{
    private Account $account;
    private CreateEventHandler $handler;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->account = new Account('DJ Mira');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getReference')->willReturn($this->account);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $this->handler = new CreateEventHandler($entityManager);
    }

    public function test_persists_open_event_with_trimmed_name_and_returns_its_id(): void
    {
        $id = ($this->handler)(new CreateEvent($this->account->getId(), '  Plaza Club  '));

        self::assertCount(1, $this->persisted);
        $event = $this->persisted[0];
        self::assertInstanceOf(Event::class, $event);
        self::assertSame('Plaza Club', $event->getName());
        self::assertSame(Event::STATUS_OPEN, $event->getStatus());
        self::assertSame($this->account, $event->getAccount());
        self::assertSame($event->getId(), $id);
    }

    public function test_slug_is_ten_characters_from_the_unambiguous_alphabet(): void
    {
        ($this->handler)(new CreateEvent($this->account->getId(), 'Plaza Club'));

        $event = $this->persisted[0];
        self::assertInstanceOf(Event::class, $event);
        self::assertMatchesRegularExpression('/^[2-9a-hjkmnp-z]{10}$/', $event->getSlug());
    }

    public function test_slugs_differ_between_events(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            ($this->handler)(new CreateEvent($this->account->getId(), 'Plaza Club'));
        }

        $slugs = array_map(static function (object $event): string {
            self::assertInstanceOf(Event::class, $event);

            return $event->getSlug();
        }, $this->persisted);
        self::assertCount(50, array_unique($slugs));
    }
}
