<?php

declare(strict_types=1);

namespace App\Requests\Entity;

use App\Accounts\Entity\Account;
use App\Requests\Repository\AccountGuestBlockRepository;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountGuestBlockRepository::class)]
#[ORM\Table(name: 'account_guest_blocks')]
#[ORM\Index(name: 'idx_account_guest_blocks_account_id', columns: ['account_id'])]
#[ORM\Index(name: 'idx_account_guest_blocks_guest_id', columns: ['guest_id'])]
#[ORM\UniqueConstraint(name: 'uk_account_guest_blocks_account_guest', columns: ['account_id', 'guest_id'])]
class AccountGuestBlock
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    private Account $account;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'guest_id', nullable: false)]
    private Guest $guest;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Account $account, Guest $guest)
    {
        $this->id = EntityId::generate();
        $this->account = $account;
        $this->guest = $guest;
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

    public function getGuest(): Guest
    {
        return $this->guest;
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
