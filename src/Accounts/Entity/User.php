<?php

declare(strict_types=1);

namespace App\Accounts\Entity;

use App\Accounts\Repository\UserRepository;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'idx_users_account_id', columns: ['account_id'])]
#[ORM\UniqueConstraint(name: 'uk_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    private Account $account;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Account $account, string $email, string $passwordHash)
    {
        $this->id = EntityId::generate();
        $this->account = $account;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    // Required by the 7.x interface; #[\Deprecated] stops Symfony from calling it (deprecated since 7.3).
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    /**
     * Keeps the Account graph and the real hash out of the session. The crc32c of the hash still lets
     * ContextListener::hasUserChanged() log out other sessions after a password change, and
     * EntityUserProvider::refreshUser() reloads the user by id.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            "\0".self::class."\0id" => $this->id,
            "\0".self::class."\0email" => $this->email,
            "\0".self::class."\0passwordHash" => hash('crc32c', $this->passwordHash),
        ];
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
