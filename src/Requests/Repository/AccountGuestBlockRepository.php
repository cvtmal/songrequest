<?php

declare(strict_types=1);

namespace App\Requests\Repository;

use App\Requests\Entity\AccountGuestBlock;
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
}
