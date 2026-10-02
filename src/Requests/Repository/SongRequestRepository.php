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
}
