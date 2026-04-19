<?php

namespace App\Service;

use App\Entity\Events;
use App\Entity\DynamicPricingRule;
use App\Entity\EventPriceHistory;
use App\Entity\EventPopularityMetric;
use App\Repository\EventsRepository;
use App\Repository\DynamicPricingRuleRepository;
use App\Repository\EventPriceHistoryRepository;
use App\Repository\EventPopularityMetricRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DynamicPricingEngine
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private EventsRepository $eventsRepository;
    private DynamicPricingRuleRepository $pricingRuleRepository;
    private EventPriceHistoryRepository $priceHistoryRepository;
    private EventPopularityMetricRepository $popularityMetricRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        EventsRepository $eventsRepository,
        DynamicPricingRuleRepository $pricingRuleRepository,
        EventPriceHistoryRepository $priceHistoryRepository,
        EventPopularityMetricRepository $popularityMetricRepository
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->eventsRepository = $eventsRepository;
        $this->pricingRuleRepository = $pricingRuleRepository;
        $this->priceHistoryRepository = $priceHistoryRepository;
        $this->popularityMetricRepository = $popularityMetricRepository;
    }

    /**
     * Calcule le prix dynamique pour un événement
     */
    public function calculateDynamicPrice(Events $event): ?float
    {
        try {
            $basePrice = (float)$event->getPrix();
            $currentPrice = $this->getCurrentPrice($event);
            
            // Récupérer la règle de pricing applicable
            $rule = $this->getApplicableRule($event);
            if (!$rule || !$rule->isActive()) {
                return $currentPrice; // Pas de règle applicable
            }

            // Calculer les facteurs
            $timeFactor = $this->calculateTimeFactor($event);
            $occupancyFactor = $this->calculateOccupancyFactor($event, $rule);
            $popularityFactor = $this->calculatePopularityFactor($event);

            // Score composite (0-1)
            $urgencyScore = ($timeFactor * (float)$rule->getTimeWeight()) +
                           ($occupancyFactor * (float)$rule->getOccupancyWeight()) +
                           ($popularityFactor * (float)$rule->getPopularityWeight());

            // Calcul réduction non-linéaire
            $discountPercentage = $this->applyNonLinearCurve($urgencyScore);

            // Prix plancher émotionnel
            $emotionalFloor = $rule->getEmotionalFloorPrice($basePrice);
            $newPrice = max($basePrice * (1 - $discountPercentage), $emotionalFloor);

            // Logique de réversibilité
            if ($this->shouldIncreasePrice($event, $newPrice, $rule)) {
                $newPrice = min($newPrice * 1.05, $basePrice * 0.95); // Max 95% du prix base
            }

            // Limiter la réduction maximale
            $maxDiscountPrice = $rule->getMaxDiscountPrice($basePrice);
            $newPrice = max($newPrice, $maxDiscountPrice);

            return round($newPrice, 2);

        } catch (\Exception $e) {
            $this->logger->error('Erreur calcul prix dynamique', [
                'event_id' => $event->getId(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Applique le pricing dynamique à un événement
     */
    public function applyDynamicPricing(Events $event): ?EventPriceHistory
    {
        $currentPrice = $this->getCurrentPrice($event);
        $newPrice = $this->calculateDynamicPrice($event);

        if ($newPrice === null || abs($newPrice - $currentPrice) < 0.01) {
            return null; // Pas de changement significatif
        }

        $rule = $this->getApplicableRule($event);
        $discountPercentage = ($currentPrice - $newPrice) / $currentPrice;

        // Créer l'historique
        $history = new EventPriceHistory();
        $history->setEvent($event);
        $history->setOldPrice(number_format($currentPrice, 2));
        $history->setNewPrice(number_format($newPrice, 2));
        $history->setDiscountPercentage(number_format($discountPercentage, 4));
        
        // Facteurs de calcul
        $history->setCalculationFactors([
            'time' => $this->calculateTimeFactor($event),
            'occupancy' => $this->calculateOccupancyFactor($event, $rule),
            'popularity' => $this->calculatePopularityFactor($event),
            'urgency_score' => ($this->calculateTimeFactor($event) * (float)$rule->getTimeWeight()) +
                              ($this->calculateOccupancyFactor($event, $rule) * (float)$rule->getOccupancyWeight()) +
                              ($this->calculatePopularityFactor($event) * (float)$rule->getPopularityWeight())
        ]);

        // Déterminer la raison
        $history->setReason($this->determineChangeReason($event, $newPrice, $currentPrice));
        $history->setAutomatic(true);

        // Mettre à jour le prix de l'événement
        $event->setPrix(number_format($newPrice, 2));

        $this->entityManager->persist($history);
        $this->entityManager->flush();

        $this->logger->info('Prix dynamique appliqué', [
            'event_id' => $event->getId(),
            'old_price' => $currentPrice,
            'new_price' => $newPrice,
            'discount' => $discountPercentage * 100 . '%'
        ]);

        return $history;
    }

    /**
     * Exécute le pricing dynamique pour tous les événements éligibles
     */
    public function runDynamicPricingBatch(): array
    {
        $results = ['processed' => 0, 'updated' => 0, 'errors' => 0];
        $events = $this->eventsRepository->findEligibleForDynamicPricing();

        foreach ($events as $event) {
            try {
                $results['processed']++;
                $history = $this->applyDynamicPricing($event);
                if ($history) {
                    $results['updated']++;
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $this->logger->error('Erreur batch pricing', [
                    'event_id' => $event->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    // --- Méthodes de calcul des facteurs ---

    private function calculateTimeFactor(Events $event): float
    {
        $now = new \DateTime();
        $eventStart = $event->getDateDebut();
        $hoursUntil = ($eventStart->getTimestamp() - $now->getTimestamp()) / 3600;

        if ($hoursUntil > 168) return 0.1;      // > 7 jours : faible urgence
        if ($hoursUntil > 72) return 0.3;       // 3-7 jours : urgence modérée
        if ($hoursUntil > 24) return 0.6;       // 1-3 jours : forte urgence
        if ($hoursUntil > 12) return 0.8;       // 12-24h : très forte urgence
        return 1.0;                             // < 12h : urgence maximale
    }

    private function calculateOccupancyFactor(Events $event, DynamicPricingRule $rule): float
    {
        $capacity = $event->getCapaciteMax();
        $available = $event->getPlacesDisponibles();
        $occupied = $capacity - $available;
        $occupancyRate = $occupied / $capacity;

        $threshold = $rule->getOccupancyThreshold() / 100;

        if ($occupancyRate >= $threshold) return 0.1; // Bon remplissage
        if ($occupancyRate >= $threshold * 0.8) return 0.4;
        if ($occupancyRate >= $threshold * 0.6) return 0.7;
        return 1.0; // Très faible remplissage
    }

    private function calculatePopularityFactor(Events $event): float
    {
        $recentMetrics = $this->popularityMetricRepository->findRecentMetrics($event, 7);
        
        if (empty($recentMetrics)) {
            return 0.5; // Valeur par défaut si pas de données
        }

        $totalScore = 0;
        $count = 0;

        foreach ($recentMetrics as $metric) {
            $totalScore += (float)$metric->getCalculatedScore();
            $count++;
        }

        $averageScore = $count > 0 ? $totalScore / $count : 0.5;

        // Inverser : plus de popularité = moins de facteur de réduction
        return 1.0 - $averageScore;
    }

    private function applyNonLinearCurve(float $score): float
    {
        // Courbe exponentielle : réduction accélérée en fin de période
        return pow($score, 1.5) * 0.4; // Max 40% de réduction
    }

    // --- Méthodes utilitaires ---

    private function getApplicableRule(Events $event): ?DynamicPricingRule
    {
        // Essayer de trouver une règle spécifique au type d'événement
        $eventType = $this->getEventType($event);
        $rule = $this->pricingRuleRepository->findByEventType($eventType);
        
        if ($rule) {
            return $rule;
        }

        // Sinon, prendre la règle par défaut
        return $this->pricingRuleRepository->findDefaultRule();
    }

    private function getEventType(Events $event): string
    {
        $activities = $event->getActivities();
        if ($activities->isEmpty()) {
            return 'default';
        }

        // Prendre le type de la première activité (simplification)
        $firstActivity = $activities->first();
        return $firstActivity ? $firstActivity->getCategorie() : 'default';
    }

    private function getCurrentPrice(Events $event): float
    {
        return (float)$event->getPrix();
    }

    private function shouldIncreasePrice(Events $event, float $newPrice, DynamicPricingRule $rule): bool
    {
        $currentPrice = $this->getCurrentPrice($event);
        
        // Vérifier si le prix a déjà baissé récemment
        $recentHistory = $this->priceHistoryRepository->findRecentHistory($event, $rule->getReversibilityFactor());
        
        if (empty($recentHistory)) {
            return false;
        }

        $lastChange = $recentHistory[0];
        
        // Vérifier si la popularité a augmenté significativement
        $currentPopularity = $this->calculatePopularityFactor($event);
        $previousPopularity = $lastChange->getPopularityFactor();
        
        $popularityImprovement = $previousPopularity - $currentPopularity;
        
        return $popularityImprovement > 0.3; // 30% d'amélioration de popularité
    }

    private function determineChangeReason(Events $event, float $newPrice, float $currentPrice): string
    {
        if ($newPrice > $currentPrice) {
            return 'reversibility';
        }

        $timeFactor = $this->calculateTimeFactor($event);
        $occupancyFactor = $this->calculateOccupancyFactor($event, $this->getApplicableRule($event));
        $popularityFactor = $this->calculatePopularityFactor($event);

        $maxFactor = max($timeFactor, $occupancyFactor, $popularityFactor);

        if ($maxFactor === $timeFactor) return 'time_urgency';
        if ($maxFactor === $occupancyFactor) return 'low_occupancy';
        return 'popularity_boost';
    }

    // --- Méthodes de monitoring et reporting ---

    public function getPricingStatistics(): array
    {
        return [
            'total_events' => $this->eventsRepository->count([]),
            'eligible_events' => count($this->eventsRepository->findEligibleForDynamicPricing()),
            'active_rules' => $this->pricingRuleRepository->count(['isActive' => true]),
            'recent_changes' => $this->priceHistoryRepository->countRecentChanges(24),
            'average_discount' => $this->priceHistoryRepository->getAverageDiscount(7)
        ];
    }

    public function getEventPricingHistory(Events $event, int $days = 30): array
    {
        return $this->priceHistoryRepository->findEventHistory($event, $days);
    }

    public function simulatePricing(Events $event, array $scenarios): array
    {
        $results = [];
        $originalPrice = $this->getCurrentPrice($event);

        foreach ($scenarios as $scenario) {
            // Simuler les facteurs
            $timeFactor = $scenario['time_factor'] ?? $this->calculateTimeFactor($event);
            $occupancyFactor = $scenario['occupancy_factor'] ?? $this->calculateOccupancyFactor($event, $this->getApplicableRule($event));
            $popularityFactor = $scenario['popularity_factor'] ?? $this->calculatePopularityFactor($event);

            $rule = $this->getApplicableRule($event);
            $urgencyScore = ($timeFactor * (float)$rule->getTimeWeight()) +
                           ($occupancyFactor * (float)$rule->getOccupancyWeight()) +
                           ($popularityFactor * (float)$rule->getPopularityWeight());

            $discountPercentage = $this->applyNonLinearCurve($urgencyScore);
            $simulatedPrice = max($originalPrice * (1 - $discountPercentage), $rule->getEmotionalFloorPrice($originalPrice));

            $results[] = [
                'scenario' => $scenario['name'] ?? 'default',
                'time_factor' => $timeFactor,
                'occupancy_factor' => $occupancyFactor,
                'popularity_factor' => $popularityFactor,
                'urgency_score' => $urgencyScore,
                'discount_percentage' => $discountPercentage,
                'simulated_price' => round($simulatedPrice, 2)
            ];
        }

        return $results;
    }
}
