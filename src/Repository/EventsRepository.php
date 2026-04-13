<?php

namespace App\Repository;

use App\Entity\Events;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Events>
 */
class EventsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Events::class);
    }

    /**
     * Trouve les événements à venir dans les prochains jours
     */
    public function findUpcomingEvents(int $days = 30): array
    {
        $now = new \DateTime();
        $futureDate = (clone $now)->modify("+$days days");

        return $this->createQueryBuilder('e')
            ->where('e.date_debut >= :now')
            ->andWhere('e.date_debut <= :futureDate')
            ->andWhere('e.statut = :statut')
            ->setParameter('now', $now)
            ->setParameter('futureDate', $futureDate)
            ->setParameter('statut', Events::STATUT_ACCEPTE)
            ->orderBy('e.date_debut', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les événements par statut
     */
    public function countByStatus(): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.statut, COUNT(e.id) as count')
            ->groupBy('e.statut')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les événements récents
     */
    public function findRecentEvents(int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
