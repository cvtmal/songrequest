<?php

declare(strict_types=1);

namespace App\Events\Repository;

use App\Events\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * Fetch-joins the account, so the guest page gets the DJ's stage name without a second query.
     */
    public function findOneBySlug(string $slug): ?Event
    {
        return $this->createQueryBuilder('e')
            ->addSelect('a')
            ->join('e.account', 'a')
            ->where('e.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
