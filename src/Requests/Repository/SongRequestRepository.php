<?php

declare(strict_types=1);

namespace App\Requests\Repository;

use App\Requests\Entity\SongRequest;
use App\Shared\Uid\EntityId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SongRequest>
 */
class SongRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SongRequest::class);
    }

    /**
     * Adds the song to the open queue, or merges it into the open duplicate with votes + 1 (AS-5).
     *
     * The generated normalised columns can't be written through the ORM, so this is native SQL.
     * `WHERE status = 'new'` is required: it lets PostgreSQL infer the partial uk_requests_event_song.
     * `NOT EXISTS` turns a guest re-requesting their own queued song into a no-op: no row is
     * updated, so nothing is returned.
     *
     * @return string|null the request ID, or null when the guest already voted for the open duplicate
     */
    public function addOrVote(string $eventId, string $accountId, string $guestId, string $title, ?string $artist): ?string
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                INSERT INTO requests (id, account_id, event_id, title, artist)
                VALUES (:id, :account_id, :event_id, :title, :artist)
                ON CONFLICT (event_id, title_normalized, artist_normalized) WHERE status = 'new'
                DO UPDATE SET votes = requests.votes + 1, updated_at = CURRENT_TIMESTAMP
                    WHERE NOT EXISTS (
                        SELECT 1 FROM request_votes v WHERE v.request_id = requests.id AND v.guest_id = :guest_id
                    )
                RETURNING id
                SQL,
            [
                'id' => EntityId::generate(),
                'account_id' => $accountId,
                'event_id' => $eventId,
                'title' => $title,
                'artist' => $artist,
                'guest_id' => $guestId,
            ],
        );

        if (false === $id) {
            return null;
        }
        \assert(\is_string($id));

        return $id;
    }

    /**
     * The open queue in DQ-1 order: votes, then first request.
     *
     * Votes from guests the account has blocked are not counted, so a request with only blocked
     * voters drops out (AS-6) and an unblock brings it back. The block target is the earliest
     * visible voter: the original requester, unless that guest is blocked.
     *
     * @return list<array{id: string, title: string, artist: ?string, votes: int, nicknames: ?string, firstRequestedAt: \DateTimeImmutable, blockGuestId: string, blockNickname: ?string}>
     */
    public function findQueue(string $eventId, string $accountId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT r.id, r.title, r.artist, r.created_at,
                       count(v.id) AS votes,
                       string_agg(v.nickname, ', ' ORDER BY v.created_at, v.id) AS nicknames,
                       (array_agg(v.guest_id ORDER BY v.created_at, v.id))[1] AS block_guest_id,
                       (array_agg(v.nickname ORDER BY v.created_at, v.id))[1] AS block_nickname
                FROM requests r
                JOIN request_votes v ON v.request_id = r.id
                LEFT JOIN account_guest_blocks b ON b.account_id = r.account_id AND b.guest_id = v.guest_id
                WHERE r.event_id = :event_id AND r.account_id = :account_id AND r.status = 'new' AND b.id IS NULL
                GROUP BY r.id
                ORDER BY votes DESC, r.created_at ASC, r.id ASC
                SQL,
            ['event_id' => $eventId, 'account_id' => $accountId],
        );

        return array_map(static function (array $row): array {
            \assert(\is_string($row['id']) && \is_string($row['title']) && \is_string($row['created_at']) && \is_string($row['block_guest_id']));
            \assert((null === $row['artist'] || \is_string($row['artist'])) && (null === $row['nicknames'] || \is_string($row['nicknames'])));
            \assert(null === $row['block_nickname'] || \is_string($row['block_nickname']));

            return [
                'id' => $row['id'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'votes' => (int) $row['votes'],
                'nicknames' => $row['nicknames'],
                'firstRequestedAt' => new \DateTimeImmutable($row['created_at']),
                'blockGuestId' => $row['block_guest_id'],
                'blockNickname' => $row['block_nickname'],
            ];
        }, $rows);
    }

    /**
     * Played and skipped requests, last handled first. Votes are not filtered by blocks here.
     *
     * @return list<array{id: string, title: string, artist: ?string, status: string, votes: int, nicknames: ?string, handledAt: \DateTimeImmutable}>
     */
    public function findDone(string $eventId, string $accountId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT r.id, r.title, r.artist, r.status, r.handled_at,
                       count(v.id) AS votes,
                       string_agg(v.nickname, ', ' ORDER BY v.created_at, v.id) AS nicknames
                FROM requests r
                LEFT JOIN request_votes v ON v.request_id = r.id
                WHERE r.event_id = :event_id AND r.account_id = :account_id AND r.status <> 'new'
                GROUP BY r.id
                ORDER BY r.handled_at DESC, r.id DESC
                SQL,
            ['event_id' => $eventId, 'account_id' => $accountId],
        );

        return array_map(static function (array $row): array {
            \assert(\is_string($row['id']) && \is_string($row['title']) && \is_string($row['status']) && \is_string($row['handled_at']));
            \assert((null === $row['artist'] || \is_string($row['artist'])) && (null === $row['nicknames'] || \is_string($row['nicknames'])));

            return [
                'id' => $row['id'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'status' => $row['status'],
                'votes' => (int) $row['votes'],
                'nicknames' => $row['nicknames'],
                'handledAt' => new \DateTimeImmutable($row['handled_at']),
            ];
        }, $rows);
    }

    public function countDone(string $eventId, string $accountId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT count(*) FROM requests WHERE event_id = :event_id AND account_id = :account_id AND status <> 'new'",
            ['event_id' => $eventId, 'account_id' => $accountId],
        );
    }

    public function hasOpenDuplicate(string $eventId, string $titleNormalized, ?string $artistNormalized): bool
    {
        // IS NOT DISTINCT FROM matches uk_requests_event_song's NULLS NOT DISTINCT.
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM requests
                    WHERE event_id = :event_id AND status = 'new'
                      AND title_normalized = :title AND artist_normalized IS NOT DISTINCT FROM :artist
                )
                SQL,
            ['event_id' => $eventId, 'title' => $titleNormalized, 'artist' => $artistNormalized],
        );
    }
}
