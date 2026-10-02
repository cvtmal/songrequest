<?php

declare(strict_types=1);

namespace App\Requests\Entity;

use App\Accounts\Entity\Account;
use App\Events\Entity\Event;
use App\Requests\Repository\SongRequestRepository;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A song in an event's queue; the table is `requests`. Named SongRequest so it does not
 * clash with HttpFoundation's Request in controllers.
 */
#[ORM\Entity(repositoryClass: SongRequestRepository::class)]
#[ORM\Table(name: 'requests')]
#[ORM\Index(name: 'idx_requests_account_id', columns: ['account_id'])]
#[ORM\Index(name: 'idx_requests_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_requests_event_id_status', columns: ['event_id', 'status'])]
#[ORM\UniqueConstraint(
    name: 'uk_requests_event_song',
    columns: ['event_id', 'title_normalized', 'artist_normalized'],
    options: ['where' => "((status)::text = 'new'::text)"],
)]
class SongRequest
{
    public const STATUS_NEW = 'new';
    public const STATUS_PLAYED = 'played';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    private Account $account;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'event_id', nullable: false)]
    private Event $event;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $artist;

    #[ORM\Column(length: 200, insertable: false, updatable: false, generated: 'ALWAYS')]
    private string $titleNormalized;

    #[ORM\Column(length: 200, nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    private ?string $artistNormalized;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $votes;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_NEW])]
    private string $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $handledAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Event $event, string $title, ?string $artist)
    {
        $this->id = EntityId::generate();
        $this->account = $event->getAccount();
        $this->event = $event;
        $this->title = $title;
        $this->artist = $artist;
        $this->votes = 1;
        $this->status = self::STATUS_NEW;
        $this->handledAt = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markPlayed(\DateTimeImmutable $now): void
    {
        $this->handle(self::STATUS_PLAYED, $now);
    }

    public function markSkipped(\DateTimeImmutable $now): void
    {
        $this->handle(self::STATUS_SKIPPED, $now);
    }

    /**
     * The caller must first make sure no open duplicate exists: uk_requests_event_song allows
     * one open row per song (see ReopenRequestHandler).
     */
    public function reopen(\DateTimeImmutable $now): void
    {
        if (self::STATUS_NEW === $this->status) {
            return;
        }

        $this->status = self::STATUS_NEW;
        $this->handledAt = null;
        $this->updatedAt = $now;
    }

    private function handle(string $status, \DateTimeImmutable $now): void
    {
        // A double tap is harmless; played and skipped may switch directly.
        if ($this->status === $status) {
            return;
        }

        $this->status = $status;
        $this->handledAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getArtist(): ?string
    {
        return $this->artist;
    }

    public function getTitleNormalized(): string
    {
        return $this->titleNormalized;
    }

    public function getArtistNormalized(): ?string
    {
        return $this->artistNormalized;
    }

    public function getVotes(): int
    {
        return $this->votes;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHandledAt(): ?\DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
