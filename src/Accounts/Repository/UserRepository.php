<?php

declare(strict_types=1);

namespace App\Accounts\Repository;

use App\Accounts\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function loadUserByIdentifier(string $identifier): ?User
    {
        // Emails are stored lowercase (chk_users_email_lowercase); mobile keyboards capitalise the first letter.
        return $this->findOneBy(['email' => mb_strtolower(trim($identifier))]);
    }
}
