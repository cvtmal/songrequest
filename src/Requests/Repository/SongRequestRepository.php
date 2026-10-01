<?php

declare(strict_types=1);

namespace App\Requests\Repository;

use App\Requests\Entity\SongRequest;
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
}
