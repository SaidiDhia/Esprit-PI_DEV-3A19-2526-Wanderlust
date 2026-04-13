<?php
// src/Repository/EventsRepository.php

namespace App\Repository;

use App\Entity\Events;
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
}