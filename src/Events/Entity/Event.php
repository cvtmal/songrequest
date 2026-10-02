<?php

declare(strict_types=1);

namespace App\Events\Entity;

use App\Accounts\Entity\Account;
use App\Events\Repository\EventRepository;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'idx_events_account_id', columns: ['account_id'])]
#[ORM\UniqueConstraint(name: 'uk_events_slug', columns: ['slug'])]
class Event
{
    public const STATUS_OPEN = 'open';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    private Account $account;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $slug;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Account $account, string $name, string $slug)
    {
        $now = new \DateTimeImmutable();

        $this->id = EntityId::generate();
        $this->account = $account;
        $this->name = $name;
        $this->slug = $slug;
        $this->status = self::STATUS_OPEN;
        $this->openedAt = $now;
        $this->closedAt = null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Closing is one-way: no method leaves closed, so a closed link never takes requests again (EV-2).
     */
    public function close(\DateTimeImmutable $now): void
    {
        // A double tap is harmless.
        if (self::STATUS_CLOSED === $this->status) {
            return;
        }

        $this->status = self::STATUS_CLOSED;
        $this->closedAt = $now;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
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
