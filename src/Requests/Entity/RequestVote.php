<?php

declare(strict_types=1);

namespace App\Requests\Entity;

use App\Accounts\Entity\Account;
use App\Events\Entity\Event;
use App\Requests\Repository\RequestVoteRepository;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One accepted submission of a song by a guest, new or merged. `event` and `account` are
 * copies of the request's, so per-guest cooldown and cap counts need no join.
 */
#[ORM\Entity(repositoryClass: RequestVoteRepository::class)]
#[ORM\Table(name: 'request_votes')]
#[ORM\Index(name: 'idx_request_votes_account_id', columns: ['account_id'])]
#[ORM\Index(name: 'idx_request_votes_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_request_votes_request_id', columns: ['request_id'])]
#[ORM\Index(name: 'idx_request_votes_guest_id', columns: ['guest_id'])]
#[ORM\Index(name: 'idx_request_votes_event_id_guest_id_created_at', columns: ['event_id', 'guest_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uk_request_votes_request_guest', columns: ['request_id', 'guest_id'])]
class RequestVote
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    private Account $account;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'event_id', nullable: false)]
    private Event $event;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'request_id', nullable: false)]
    private SongRequest $request;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'guest_id', nullable: false)]
    private Guest $guest;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nickname;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(SongRequest $request, Guest $guest, ?string $nickname)
    {
        $this->id = EntityId::generate();
        $this->account = $request->getAccount();
        $this->event = $request->getEvent();
        $this->request = $request;
        $this->guest = $guest;
        $this->nickname = $nickname;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getRequest(): SongRequest
    {
        return $this->request;
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
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
