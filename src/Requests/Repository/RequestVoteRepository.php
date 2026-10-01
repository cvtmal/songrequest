<?php

declare(strict_types=1);

namespace App\Requests\Repository;

use App\Requests\Entity\RequestVote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RequestVote>
 */
class RequestVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RequestVote::class);
    }
}
