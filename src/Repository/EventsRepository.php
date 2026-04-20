<?php
// src/Repository/EventsRepository.php

namespace App\Repository;

use App\Entity\Events;
use App\Enum\StatusEventEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EventsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Events::class);
    }

    // ── Lecture ────────────────────────────────────────────────────────

    /**
     * Tous les events avec leurs activités, triés par date de début.
     */
    public function findAllWithActivites(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->addSelect('a')
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Events à venir (date_debut >= aujourd'hui).
     */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.dateDebut >= :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Events disponibles (places restantes + date limite non dépassée).
     */
    public function findAvailable(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.placesDisponibles > 0')
            ->andWhere('e.dateLimiteInscription >= :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche par lieu, organisateur ou statut.
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.lieu LIKE :q')
            ->orWhere('e.organisateur LIKE :q')
            ->orWhere('e.statut LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Events filtrés par activité.
     */
    public function findByActivite(int $activiteId): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->where('a.id = :id')
            ->setParameter('id', $activiteId)
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Events filtrés par statut.
     */
    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Events dans une fourchette de prix.
     */
    public function findByPrixRange(float $min, float $max): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.prix BETWEEN :min AND :max')
            ->setParameter('min', $min)
            ->setParameter('max', $max)
            ->orderBy('e.prix', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // ── Écriture ───────────────────────────────────────────────────────

    public function save(Events $event, bool $flush = false): void
    {
        $this->getEntityManager()->persist($event);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Events $event, bool $flush = false): void
    {
        $this->getEntityManager()->remove($event);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    // ── Pricing Dynamique ───────────────────────────────────────────────

    /**
     * Événements éligibles au pricing dynamique (à venir avec places disponibles).
     */
    public function findEligibleForDynamicPricing(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.dateDebut <= :maxDate') // Maximum 30 jours dans le futur
            ->andWhere('e.placesDisponibles > 0')
            ->andWhere('e.capaciteMax > 0')
            ->andWhere('e.prix > 0')
            ->setParameter('now', new \DateTime())
            ->setParameter('maxDate', new \DateTime('+30 days'))
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements avec faible taux de remplissage.
     */
    public function findLowOccupancyEvents(float $threshold = 0.7): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.placesDisponibles > 0')
            ->andWhere('(e.capaciteMax - e.placesDisponibles) / e.capaciteMax < :threshold')
            ->setParameter('now', new \DateTime())
            ->setParameter('threshold', $threshold)
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements approchant de leur date de début.
     */
    public function findUpcomingEvents(int $hours = 72): array
    {
        $futureDate = new \DateTime("+{$hours} hours");
        
        return $this->createQueryBuilder('e')
            ->where('e.dateDebut BETWEEN :now AND :future')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('now', new \DateTime())
            ->setParameter('future', $futureDate)
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements avec changement de prix récent.
     */
    public function findEventsWithRecentPriceChanges(int $hours = 24): array
    {
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(eph.event)')
            ->from('App\Entity\EventPriceHistory', 'eph')
            ->where('eph.createdAt >= :since')
            ->groupBy('eph.event');

        return $this->createQueryBuilder('e')
            ->where('e.id IN (' . $subQuery->getDQL() . ')')
            ->setParameter('since', new \DateTime("-{$hours} hours"))
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiques de remplissage pour un événement.
     */
    public function getOccupancyStats(Events $event): array
    {
        $capacity = $event->getCapaciteMax();
        $available = $event->getPlacesDisponibles();
        $occupied = $capacity - $available;
        
        return [
            'capacity' => $capacity,
            'available' => $available,
            'occupied' => $occupied,
            'occupancy_rate' => $capacity > 0 ? $occupied / $capacity : 0,
            'available_rate' => $capacity > 0 ? $available / $capacity : 0,
            'remaining_percentage' => $capacity > 0 ? ($available / $capacity) * 100 : 0
        ];
    }

    /**
     * Événements par type d'activité pour le pricing.
     */
    public function findByActivityType(string $activityType): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->where('a.categorie = :type')
            ->andWhere('e.dateDebut >= :now')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('type', $activityType)
            ->setParameter('now', new \DateTime())
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements avec prix dans une fourchette pour analyse.
     */
    public function findByPriceRangeForAnalysis(float $min, float $max): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.prix BETWEEN :min AND :max')
            ->andWhere('e.dateDebut >= :now')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('min', $min)
            ->setParameter('max', $max)
            ->setParameter('now', new \DateTime())
            ->orderBy('e.prix', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les événements éligibles par statut.
     */
    public function countEligibleByStatut(): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.statut', 'COUNT(e.id) as count')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('now', new \DateTime())
            ->groupBy('e.statut')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements needing pricing update (basé sur la dernière mise à jour).
     */
    public function findEventsNeedingPricingUpdate(int $hours = 4): array
    {
        $cutoffTime = new \DateTime("-{$hours} hours");
        
        return $this->createQueryBuilder('e')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.placesDisponibles > 0')
            ->andWhere('(e.dateModification IS NULL OR e.dateModification < :cutoff)')
            ->setParameter('now', new \DateTime())
            ->setParameter('cutoff', $cutoffTime)
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // ---- SYSTÈME DE SUGGESTIONS AVANCÉES ----

    /**
     * Suggestions par catégorie (filtre via JOIN sur les activités associées à l'event).
     * $category : valeur de CategorieActiviteEnum (ex: 'desert', 'Mer', 'Aérien', 'nature', 'Culture')
     * $participantCount : nombre minimum de places souhaitées
     */
    public function findByCategorySuggestion(?string $category = null, ?int $participantCount = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->addSelect('a')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.status = :acceptedStatus')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('now', new \DateTime())
            ->setParameter('acceptedStatus', StatusEventEnum::ACCEPTE);

        if ($category) {
            // Filtre via la catégorie des activités associées à l'event
            $qb->andWhere('a.categorie = :category')
               ->setParameter('category', $category);
        }

        if ($participantCount !== null && $participantCount > 0) {
            $qb->andWhere('e.placesDisponibles >= :participants')
               ->setParameter('participants', $participantCount);
        }

        return $qb->distinct()
                  ->orderBy('e.dateDebut', 'ASC')
                  ->setMaxResults(12)
                  ->getQuery()
                  ->getResult();
    }

    /**
     * Suggestions adaptées aux enfants (filtre sur ageMinimum des activités).
     * - $childrenFriendly = true  → events avec activités pour enfants (ageMinimum <= minAge ou null)
     * - $childrenFriendly = false → events sans restriction d'âge stricte
     * - $minAge : âge minimum de l'enfant → ne montrer que les events où ageMinimum <= minAge
     * - $nbrEnfants : nombre d'enfants → l'event doit avoir au moins ce nombre de places
     */
    public function findByAgeSuggestion(bool $childrenFriendly = true, ?int $minAge = null, ?int $nbrEnfants = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->addSelect('a')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.status = :acceptedStatus')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('now', new \DateTime())
            ->setParameter('acceptedStatus', StatusEventEnum::ACCEPTE);

        if ($childrenFriendly) {
            if ($minAge !== null) {
                // N'afficher que les events avec activités où ageMinimum est nul ou <= à l'âge de l'enfant
                $qb->andWhere('a.ageMinimum IS NULL OR a.ageMinimum <= :minAge')
                   ->setParameter('minAge', $minAge);
            } else {
                // Juste des events avec des activités ayant un ageMinimum défini (dédiées enfants)
                $qb->andWhere('a.ageMinimum IS NOT NULL');
            }
        } else {
            // Pas avec enfants : events sans restriction d'âge (ageMinimum null ou 0)
            $qb->andWhere('a.ageMinimum IS NULL OR a.ageMinimum = 0');
        }

        if ($nbrEnfants !== null && $nbrEnfants > 0) {
            $qb->andWhere('e.placesDisponibles >= :nbrEnfants')
               ->setParameter('nbrEnfants', $nbrEnfants);
        }

        return $qb->distinct()
                  ->orderBy('a.ageMinimum', 'ASC')
                  ->addOrderBy('e.dateDebut', 'ASC')
                  ->setMaxResults(12)
                  ->getQuery()
                  ->getResult();
    }

    /**
     * Suggestions par localisation.
     * Recherche les events dont le champ `lieu` contient le nom de la ville saisie.
     * La ville peut être obtenue automatiquement via Nominatim (reverse geocoding GPS).
     */
    public function findByLocationSuggestion(?string $city = null, ?int $participantCount = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->addSelect('a')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.status = :acceptedStatus')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('now', new \DateTime())
            ->setParameter('acceptedStatus', StatusEventEnum::ACCEPTE);

        if ($city) {
            // Recherche insensible à la casse par nom de ville dans le champ lieu
            $qb->andWhere('LOWER(e.lieu) LIKE :city')
               ->setParameter('city', '%' . strtolower(trim($city)) . '%');
        }

        if ($participantCount !== null && $participantCount > 0) {
            $qb->andWhere('e.placesDisponibles >= :participants')
               ->setParameter('participants', $participantCount);
        }

        return $qb->distinct()
                  ->orderBy('e.dateDebut', 'ASC')
                  ->setMaxResults(12)
                  ->getQuery()
                  ->getResult();
    }

    /**
     * Suggestions générales (tous les événements à venir avec places disponibles).
     */
    public function findGeneralSuggestions(?int $participantCount = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.activities', 'a')
            ->addSelect('a')
            ->where('e.dateDebut >= :now')
            ->andWhere('e.status = :acceptedStatus')
            ->andWhere('e.placesDisponibles > 0')
            ->setParameter('now', new \DateTime())
            ->setParameter('acceptedStatus', StatusEventEnum::ACCEPTE);

        if ($participantCount !== null && $participantCount > 0) {
            $qb->andWhere('e.placesDisponibles >= :participants')
               ->setParameter('participants', $participantCount);
        }

        return $qb->distinct()
                  ->orderBy('e.dateDebut', 'ASC')
                  ->setMaxResults(12)
                  ->getQuery()
                  ->getResult();
    }
}