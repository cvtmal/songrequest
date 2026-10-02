<?php

declare(strict_types=1);

namespace App\Events\Repository;

use App\Accounts\Entity\Account;
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

    /**
     * DJ routes use this, so another account's event is "not found".
     */
    public function findOneForAccount(string $id, Account $account): ?Event
    {
        return $this->createQueryBuilder('e')
            ->where('e.id = :id')
            ->andWhere('e.account = :account')
            ->setParameter('id', $id)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The id tie-break keeps the order stable, because UUID v7 sorts by time.
     *
     * @return list<Event>
     */
    public function findForAccount(Account $account): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.account = :account')
            ->setParameter('account', $account)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
