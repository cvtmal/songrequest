<?php

declare(strict_types=1);

namespace App\Requests\Repository;

use App\Requests\Entity\RequestVote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RequestVote>
 */
class RequestVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RequestVote::class);
    }

    /**
     * Votes of every request status count: the cap is per event, for the whole night (AS-3).
     *
     * @return array{count: int, lastCreatedAt: ?\DateTimeImmutable}
     */
    public function guestActivityInEvent(string $eventId, string $guestId): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT count(*) AS total, max(created_at) AS last_created_at
                FROM request_votes
                WHERE event_id = :event_id AND guest_id = :guest_id
                SQL,
            ['event_id' => $eventId, 'guest_id' => $guestId],
        );
        \assert(\is_array($row));

        $lastCreatedAt = $row['last_created_at'];
        \assert(null === $lastCreatedAt || \is_string($lastCreatedAt));

        return [
            'count' => (int) $row['total'],
            'lastCreatedAt' => null === $lastCreatedAt ? null : new \DateTimeImmutable($lastCreatedAt),
        ];
    }

    /**
     * Counts accepted submissions only; rejected and muted ones leave no vote behind (AS-7).
     */
    public function countByIpSince(string $eventId, string $ipAddress, \DateTimeImmutable $since): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT count(*)
                FROM request_votes
                WHERE event_id = :event_id AND ip_address = :ip_address AND created_at > :since
                SQL,
            ['event_id' => $eventId, 'ip_address' => $ipAddress, 'since' => $since],
            ['since' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * A DJ only knows guests who voted in one of their events, so this is the tenancy check for blocking.
     */
    public function guestVotedForAccount(string $accountId, string $guestId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM request_votes WHERE account_id = :account_id AND guest_id = :guest_id)',
            ['account_id' => $accountId, 'guest_id' => $guestId],
        );
    }
}
