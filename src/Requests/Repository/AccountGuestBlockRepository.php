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
}
