<?php

namespace App\Repository;

use App\Entity\EventPriceHistory;
use App\Entity\Events;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EventPriceHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventPriceHistory::class);
    }

    public function save(EventPriceHistory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EventPriceHistory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Récupérer les changements de prix récents
     */
    public function findRecentChanges(int $limit = 10): array
    {
        return $this->createQueryBuilder('h')
            ->leftJoin('h.event', 'e')
            ->addSelect('e')
            ->orderBy('h.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les changements récents
     */
    public function countRecentChanges(int $hours = 24): int
    {
        $since = new \DateTime("-{$hours} hours");
        
        return $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Historique d'un événement
     */
    public function findEventHistory(Events $event, int $days = 30): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('h')
            ->where('h.event = :event')
            ->andWhere('h.createdAt >= :since')
            ->setParameter('event', $event)
            ->setParameter('since', $since)
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Historique récent d'un événement pour la réversibilité
     */
    public function findRecentHistory(Events $event, int $days): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('h')
            ->where('h.event = :event')
            ->andWhere('h.createdAt >= :since')
            ->setParameter('event', $event)
            ->setParameter('since', $since)
            ->orderBy('h.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
    }

    /**
     * Réduction moyenne sur une période
     */
    public function getAverageDiscount(int $days = 7): ?float
    {
        $since = new \DateTime("-{$days} days");
        
        $result = $this->createQueryBuilder('h')
            ->select('AVG(h.discountPercentage)')
            ->where('h.createdAt >= :since')
            ->andWhere('h.discountPercentage > 0')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
        
        return $result ? (float)$result : null;
    }

    /**
     * Changements de prix par raison
     */
    public function getPriceChangesByReason(int $days = 30): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('h')
            ->select('h.reason', 'COUNT(h.id) as count', 'AVG(h.discountPercentage) as avg_discount')
            ->where('h.createdAt >= :since')
            ->groupBy('h.reason')
            ->orderBy('count', 'DESC')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /**
     * Réduction moyenne par jour
     */
    public function getAverageDiscountByDay(int $days = 30): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('h')
            ->select('DATE(h.createdAt) as date', 'AVG(h.discountPercentage) as avg_discount', 'COUNT(h.id) as count')
            ->where('h.createdAt >= :since')
            ->andWhere('h.discountPercentage > 0')
            ->groupBy('DATE(h.createdAt)')
            ->orderBy('date', 'ASC')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements avec le plus de changements de prix
     */
    public function getEventsWithMostChanges(int $limit = 10, int $days = 30): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('h')
            ->select('e.id', 'e.lieu', 'COUNT(h.id) as change_count', 'AVG(h.discountPercentage) as avg_discount')
            ->leftJoin('h.event', 'e')
            ->where('h.createdAt >= :since')
            ->groupBy('e.id', 'e.lieu')
            ->orderBy('change_count', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiques des réductions
     */
    public function getDiscountStatistics(int $days = 30): array
    {
        $since = new \DateTime("-{$days} days");
        
        $stats = $this->createQueryBuilder('h')
            ->select('
                COUNT(h.id) as total_changes,
                AVG(h.discountPercentage) as avg_discount,
                MIN(h.discountPercentage) as min_discount,
                MAX(h.discountPercentage) as max_discount,
                SUM(CASE WHEN h.discountPercentage > 0 THEN 1 ELSE 0 END) as price_decreases,
                SUM(CASE WHEN h.discountPercentage < 0 THEN 1 ELSE 0 END) as price_increases
            ')
            ->where('h.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();
        
        return [
            'total_changes' => (int)$stats['total_changes'],
            'avg_discount' => $stats['avg_discount'] ? (float)$stats['avg_discount'] : 0,
            'min_discount' => $stats['min_discount'] ? (float)$stats['min_discount'] : 0,
            'max_discount' => $stats['max_discount'] ? (float)$stats['max_discount'] : 0,
            'price_decreases' => (int)$stats['price_decreases'],
            'price_increases' => (int)$stats['price_increases']
        ];
    }
}
