<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Events\Entity\Event;
use App\Factory\AccountFactory;
use App\Factory\AccountGuestBlockFactory;
use App\Factory\EventFactory;
use App\Factory\GuestFactory;
use App\Factory\RequestVoteFactory;
use App\Factory\SongRequestFactory;
use App\Requests\Entity\Guest;
use App\Requests\Repository\AccountGuestBlockRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AccountGuestBlockRepositoryTest extends KernelTestCase
{
    public function test_block_twice_keeps_one_row(): void
    {
        $account = AccountFactory::createOne();
        $guest = GuestFactory::createOne();

        $this->repository()->block($account->getId(), $guest->getId());
        $this->repository()->block($account->getId(), $guest->getId());

        self::assertSame(1, $this->countBlocks());
    }

    public function test_unblock_removes_only_this_accounts_block(): void
    {
        $mine = AccountFactory::createOne();
        $theirs = AccountFactory::createOne();
        $guest = GuestFactory::createOne();
        $this->repository()->block($mine->getId(), $guest->getId());
        $this->repository()->block($theirs->getId(), $guest->getId());

        $this->repository()->unblock($mine->getId(), $guest->getId());

        self::assertFalse($this->repository()->isBlocked($mine->getId(), $guest->getId()));
        self::assertTrue($this->repository()->isBlocked($theirs->getId(), $guest->getId()));
    }

    public function test_find_for_account_shows_latest_nickname_and_last_request(): void
    {
        $event = EventFactory::createOne();
        $lea = GuestFactory::createOne();
        $this->vote($event, $lea, 'Macarena', 'Lea', '2026-10-03 21:00:00+00');
        $this->vote($event, $lea, 'Dreams', 'Lea M', '2026-10-03 21:10:00+00');
        $this->vote($event, $lea, 'Padam Padam', null, '2026-10-03 21:20:00+00');
        $nico = GuestFactory::createOne();
        $this->vote($event, $nico, 'Get Lucky', 'Nico', '2026-10-03 21:05:00+00');
        $this->block($event, $lea, '2026-10-03 22:00:00+00');
        $this->block($event, $nico, '2026-10-03 22:10:00+00');
        AccountGuestBlockFactory::createOne(['guest' => GuestFactory::createOne()]);

        $rows = $this->repository()->findForAccount($event->getAccount()->getId());

        self::assertSame([$nico->getId(), $lea->getId()], array_column($rows, 'guestId'));
        self::assertSame('Lea M', $rows[1]['nickname']);
        self::assertSame('Padam Padam', $rows[1]['lastTitle']);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 22:00:00+00'), $rows[1]['blockedAt']);
    }

    public function test_find_for_account_handles_guest_without_nickname(): void
    {
        $event = EventFactory::createOne();
        $guest = GuestFactory::createOne();
        $this->vote($event, $guest, 'Macarena', null, '2026-10-03 21:00:00+00', 'Los del Río');
        $this->block($event, $guest, '2026-10-03 22:00:00+00');

        $rows = $this->repository()->findForAccount($event->getAccount()->getId());

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['nickname']);
        self::assertSame('Macarena', $rows[0]['lastTitle']);
        self::assertSame('Los del Río', $rows[0]['lastArtist']);
    }

    public function test_count_for_account(): void
    {
        $account = AccountFactory::createOne();
        $this->repository()->block($account->getId(), GuestFactory::createOne()->getId());
        $this->repository()->block($account->getId(), GuestFactory::createOne()->getId());
        AccountGuestBlockFactory::createOne();

        self::assertSame(2, $this->repository()->countForAccount($account->getId()));
    }

    private function vote(Event $event, Guest $guest, string $title, ?string $nickname, string $createdAt, ?string $artist = null): void
    {
        $vote = RequestVoteFactory::createOne([
            'request' => SongRequestFactory::createOne(['event' => $event, 'title' => $title, 'artist' => $artist]),
            'guest' => $guest,
            'nickname' => $nickname,
        ]);
        $this->connection()->update('request_votes', ['created_at' => $createdAt], ['id' => $vote->getId()]);
    }

    private function block(Event $event, Guest $guest, string $createdAt): void
    {
        $block = AccountGuestBlockFactory::createOne(['account' => $event->getAccount(), 'guest' => $guest]);
        $this->connection()->update('account_guest_blocks', ['created_at' => $createdAt], ['id' => $block->getId()]);
    }

    private function countBlocks(): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM account_guest_blocks');
    }

    private function repository(): AccountGuestBlockRepository
    {
        $repository = self::getContainer()->get(AccountGuestBlockRepository::class);
        self::assertInstanceOf(AccountGuestBlockRepository::class, $repository);

        return $repository;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
