<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Events\Entity\Event;
use App\Factory\EventFactory;
use App\Requests\Command\BlockGuest;
use App\Requests\Command\SubmitSongRequest;
use App\Requests\Command\UnblockGuest;
use App\Requests\Exception\GuestNotFound;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class GuestBlockHandlersTest extends KernelTestCase
{
    public function test_block_guest_who_voted_in_own_event(): void
    {
        $event = EventFactory::createOne();
        $guestId = $this->guestWhoRequested($event);

        $this->dispatch(new BlockGuest($guestId, $event->getAccount()->getId()));

        self::assertSame(1, $this->countBlocks());
    }

    public function test_blocking_twice_is_harmless(): void
    {
        $event = EventFactory::createOne();
        $guestId = $this->guestWhoRequested($event);

        $this->dispatch(new BlockGuest($guestId, $event->getAccount()->getId()));
        $this->dispatch(new BlockGuest($guestId, $event->getAccount()->getId()));

        self::assertSame(1, $this->countBlocks());
    }

    public function test_guest_of_another_account_is_not_found(): void
    {
        $event = EventFactory::createOne();
        $guestId = $this->guestWhoRequested(EventFactory::createOne());

        try {
            $this->dispatch(new BlockGuest($guestId, $event->getAccount()->getId()));
            self::fail('Expected GuestNotFound.');
        } catch (GuestNotFound) {
        }

        self::assertSame(0, $this->countBlocks());
    }

    public function test_unblock_removes_the_block_and_is_idempotent(): void
    {
        $event = EventFactory::createOne();
        $guestId = $this->guestWhoRequested($event);
        $this->dispatch(new BlockGuest($guestId, $event->getAccount()->getId()));

        $this->dispatch(new UnblockGuest($guestId, $event->getAccount()->getId()));
        $this->dispatch(new UnblockGuest($guestId, $event->getAccount()->getId()));

        self::assertSame(0, $this->countBlocks());
    }

    private function guestWhoRequested(Event $event): string
    {
        $token = Uuid::v4()->toRfc4122();
        $this->dispatch(new SubmitSongRequest($event->getId(), $token, 'Macarena', null, 'Lea', null));

        $id = $this->connection()->fetchOne('SELECT id FROM guests WHERE token = :token', ['token' => $token]);
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

    private function countBlocks(): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM account_guest_blocks');
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
