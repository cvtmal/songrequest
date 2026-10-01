<?php

declare(strict_types=1);

namespace App\Tests\Integration\Requests;

use App\Factory\AccountFactory;
use App\Factory\GuestFactory;
use App\Factory\SongRequestFactory;
use App\Requests\Entity\Guest;
use App\Requests\Entity\SongRequest;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\ConstraintAssertions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class GuestsAndVotesConstraintsTest extends KernelTestCase
{
    use ConstraintAssertions;

    public function test_guest_token_is_unique(): void
    {
        $token = Uuid::v4()->toRfc4122();
        $this->insertGuest($token);

        self::assertUniqueViolation('uk_guests_token', fn () => $this->insertGuest($token));
    }

    public function test_guest_votes_once_per_request(): void
    {
        $request = SongRequestFactory::createOne();
        $guest = GuestFactory::createOne();
        $this->insertVote($request, $guest);

        self::assertUniqueViolation('uk_request_votes_request_guest', fn () => $this->insertVote($request, $guest));
    }

    public function test_two_guests_can_vote_for_the_same_request(): void
    {
        $request = SongRequestFactory::createOne();
        $this->insertVote($request, GuestFactory::createOne());
        $this->insertVote($request, GuestFactory::createOne());

        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT count(*) FROM request_votes'));
    }

    public function test_guest_is_blocked_once_per_account(): void
    {
        $accountId = AccountFactory::createOne()->getId();
        $guestId = GuestFactory::createOne()->getId();
        $this->insertBlock($accountId, $guestId);

        self::assertUniqueViolation(
            'uk_account_guest_blocks_account_guest',
            fn () => $this->insertBlock($accountId, $guestId),
        );
    }

    private function insertGuest(string $token): void
    {
        $this->connection()->insert('guests', ['id' => EntityId::generate(), 'token' => $token]);
    }

    private function insertVote(SongRequest $request, Guest $guest): void
    {
        $this->connection()->insert('request_votes', [
            'id' => EntityId::generate(),
            'account_id' => $request->getAccount()->getId(),
            'event_id' => $request->getEvent()->getId(),
            'request_id' => $request->getId(),
            'guest_id' => $guest->getId(),
            'nickname' => 'Anna',
        ]);
    }

    private function insertBlock(string $accountId, string $guestId): void
    {
        $this->connection()->insert('account_guest_blocks', [
            'id' => EntityId::generate(),
            'account_id' => $accountId,
            'guest_id' => $guestId,
        ]);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
