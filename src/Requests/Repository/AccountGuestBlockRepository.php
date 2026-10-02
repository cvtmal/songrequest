<?php

declare(strict_types=1);

namespace App\Requests\Repository;

use App\Requests\Entity\AccountGuestBlock;
use App\Shared\Uid\EntityId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountGuestBlock>
 */
class AccountGuestBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountGuestBlock::class);
    }

    public function isBlocked(string $accountId, string $guestId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM account_guest_blocks WHERE account_id = :account_id AND guest_id = :guest_id)',
            ['account_id' => $accountId, 'guest_id' => $guestId],
        );
    }

    public function block(string $accountId, string $guestId): void
    {
        // Two taps cannot raise a unique violation (mirrors SongRequestRepository::addOrVote).
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO account_guest_blocks (id, account_id, guest_id)
                VALUES (:id, :account_id, :guest_id)
                ON CONFLICT (account_id, guest_id) DO NOTHING
                SQL,
            ['id' => EntityId::generate(), 'account_id' => $accountId, 'guest_id' => $guestId],
        );
    }

    public function unblock(string $accountId, string $guestId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM account_guest_blocks WHERE account_id = :account_id AND guest_id = :guest_id',
            ['account_id' => $accountId, 'guest_id' => $guestId],
        );
    }

    /**
     * Newest block first, with the guest's latest nickname and last requested song in this
     * account's events, so the DJ can tell who is who.
     *
     * @return list<array{guestId: string, blockedAt: \DateTimeImmutable, nickname: ?string, lastTitle: ?string, lastArtist: ?string}>
     */
    public function findForAccount(string $accountId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT b.guest_id, b.created_at,
                       (SELECT v.nickname FROM request_votes v
                        WHERE v.guest_id = b.guest_id AND v.account_id = b.account_id AND v.nickname IS NOT NULL
                        ORDER BY v.created_at DESC, v.id DESC LIMIT 1) AS nickname,
                       last.title, last.artist
                FROM account_guest_blocks b
                LEFT JOIN LATERAL (
                    SELECT r.title, r.artist FROM request_votes v JOIN requests r ON r.id = v.request_id
                    WHERE v.guest_id = b.guest_id AND v.account_id = b.account_id
                    ORDER BY v.created_at DESC, v.id DESC LIMIT 1
                ) last ON true
                WHERE b.account_id = :account_id
                ORDER BY b.created_at DESC, b.id DESC
                SQL,
            ['account_id' => $accountId],
        );

        return array_map(static function (array $row): array {
            \assert(\is_string($row['guest_id']) && \is_string($row['created_at']));
            \assert(null === $row['nickname'] || \is_string($row['nickname']));
            \assert((null === $row['title'] || \is_string($row['title'])) && (null === $row['artist'] || \is_string($row['artist'])));

            return [
                'guestId' => $row['guest_id'],
                'blockedAt' => new \DateTimeImmutable($row['created_at']),
                'nickname' => $row['nickname'],
                'lastTitle' => $row['title'],
                'lastArtist' => $row['artist'],
            ];
        }, $rows);
    }

    public function countForAccount(string $accountId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM account_guest_blocks WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );
    }
}
