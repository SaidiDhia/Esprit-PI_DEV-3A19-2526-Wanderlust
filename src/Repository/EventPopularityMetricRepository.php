<?php

namespace App\Repository;

use App\Entity\EventPopularityMetric;
use App\Entity\Events;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EventPopularityMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventPopularityMetric::class);
    }

    public function save(EventPopularityMetric $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EventPopularityMetric $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Métriques récentes pour un événement
     */
    public function findRecentMetrics(Events $event, int $days = 7): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('m')
            ->where('m.event = :event')
            ->andWhere('m.date >= :since')
            ->setParameter('event', $event)
            ->setParameter('since', $since)
            ->orderBy('m.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Métrique du jour pour un événement
     */
    public function findTodayMetric(Events $event): ?EventPopularityMetric
    {
        $today = new \DateTime('today');
        
        return $this->createQueryBuilder('m')
            ->where('m.event = :event')
            ->andWhere('m.date = :date')
            ->setParameter('event', $event)
            ->setParameter('date', $today)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Créer ou mettre à jour la métrique du jour
     */
    public function createOrUpdateTodayMetric(Events $event, array $data): EventPopularityMetric
    {
        $metric = $this->findTodayMetric($event);
        
        if (!$metric) {
            $metric = new EventPopularityMetric();
            $metric->setEvent($event);
            $metric->setDate(new \DateTime('today'));
        }

        // Mettre à jour les métriques
        $metric->setViewsCount($data['views_count'] ?? $metric->getViewsCount());
        $metric->setCartAbandonments($data['cart_abandonments'] ?? $metric->getCartAbandonments());
        $metric->setSocialShares($data['social_shares'] ?? $metric->getSocialShares());
        $metric->setSearchMentions($data['search_mentions'] ?? $metric->getSearchMentions());
        $metric->setReservationsCount($data['reservations_count'] ?? $metric->getReservationsCount());
        $metric->setAverageTimeOnPage($data['average_time_on_page'] ?? $metric->getAverageTimeOnPage());
        
        // Ajouter des métriques brutes supplémentaires
        foreach ($data['raw_metrics'] ?? [] as $key => $value) {
            $metric->addRawMetric($key, $value);
        }

        $this->save($metric, true);
        
        return $metric;
    }

    /**
     * Score de popularité moyen pour un événement
     */
    public function getAveragePopularityScore(Events $event, int $days = 7): float
    {
        $metrics = $this->findRecentMetrics($event, $days);
        
        if (empty($metrics)) {
            return 0.0;
        }

        $totalScore = 0;
        foreach ($metrics as $metric) {
            $totalScore += (float)$metric->getCalculatedScore();
        }

        return $totalScore / count($metrics);
    }

    /**
     * Tendance de popularité (en hausse/baisse)
     */
    public function getPopularityTrend(Events $event, int $days = 7): array
    {
        $metrics = $this->findRecentMetrics($event, $days);
        
        if (count($metrics) < 2) {
            return ['trend' => 'stable', 'change' => 0];
        }

        $recent = (float)$metrics[0]->getCalculatedScore();
        $previous = (float)$metrics[1]->getCalculatedScore();
        
        $change = $recent - $previous;
        
        if ($change > 0.1) {
            $trend = 'increasing';
        } elseif ($change < -0.1) {
            $trend = 'decreasing';
        } else {
            $trend = 'stable';
        }

        return [
            'trend' => $trend,
            'change' => $change,
            'recent_score' => $recent,
            'previous_score' => $previous
        ];
    }

    /**
     * Événements les plus populaires
     */
    public function getMostPopularEvents(int $limit = 10, int $days = 7): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('m')
            ->select('e.id', 'e.lieu', 'AVG(m.calculatedScore) as avg_score', 'COUNT(m.id) as metric_count')
            ->leftJoin('m.event', 'e')
            ->where('m.date >= :since')
            ->groupBy('e.id', 'e.lieu')
            ->orderBy('avg_score', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements avec la plus forte croissance de popularité
     */
    public function getFastestGrowingEvents(int $limit = 10, int $days = 7): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('m')
            ->select('e.id', 'e.lieu', 
                '(MAX(m.calculatedScore) - MIN(m.calculatedScore)) as growth',
                'AVG(m.calculatedScore) as avg_score'
            )
            ->leftJoin('m.event', 'e')
            ->where('m.date >= :since')
            ->groupBy('e.id', 'e.lieu')
            ->having('COUNT(m.id) >= 2') // Au moins 2 métriques pour calculer la croissance
            ->orderBy('growth', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /**
     * Nettoyer les anciennes métriques
     */
    public function cleanupOldMetrics(int $daysToKeep = 90): int
    {
        $cutoffDate = new \DateTime("-{$daysToKeep} days");
        
        $deleted = $this->createQueryBuilder('m')
            ->delete()
            ->where('m.date < :cutoff')
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->execute();
        
        return $deleted;
    }

    /**
     * Statistiques générales de popularité
     */
    public function getPopularityStatistics(int $days = 7): array
    {
        $since = new \DateTime("-{$days} days");
        
        $stats = $this->createQueryBuilder('m')
            ->select('
                COUNT(DISTINCT m.event) as events_tracked,
                AVG(m.calculatedScore) as avg_score,
                MIN(m.calculatedScore) as min_score,
                MAX(m.calculatedScore) as max_score,
                SUM(m.viewsCount) as total_views,
                SUM(m.socialShares) as total_shares,
                SUM(m.reservationsCount) as total_reservations
            ')
            ->where('m.date >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();
        
        return [
            'events_tracked' => (int)$stats['events_tracked'],
            'avg_score' => $stats['avg_score'] ? (float)$stats['avg_score'] : 0,
            'min_score' => $stats['min_score'] ? (float)$stats['min_score'] : 0,
            'max_score' => $stats['max_score'] ? (float)$stats['max_score'] : 0,
            'total_views' => (int)$stats['total_views'],
            'total_shares' => (int)$stats['total_shares'],
            'total_reservations' => (int)$stats['total_reservations']
        ];
    }
}
