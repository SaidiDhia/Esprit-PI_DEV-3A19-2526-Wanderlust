<?php

namespace App\Repository;

use App\Entity\Reservations;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ReservationsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservations::class);
    }

    /**
     * Trouve toutes les réservations pour un événement
     */
    public function findByEvent(int $eventId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.event = :id')
            ->setParameter('id', $eventId)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les réservations par statut
     */
    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre total de personnes pour un événement
     */
    public function countTotalPersonsByEvent(int $eventId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('SUM(r.nombrePersonnes)')
            ->where('r.event = :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Trouve les réservations par email
     */
    public function findByEmail(string $email): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.email = :email')
            ->setParameter('email', $email)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les réservations par statut
     */
    public function countByStatut(string $statut): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.statut = :statut')
            ->setParameter('statut', $statut)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouve les réservations récentes
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.dateCreation', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcule le revenu total par événement
     */
    public function calculateRevenueByEvent(int $eventId): float
    {
        return (float) $this->createQueryBuilder('r')
            ->select('SUM(r.prixTotal)')
            ->where('r.event = :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Sauvegarde une réservation
     */
    public function save(Reservations $reservation, bool $flush = false): void
    {
        $this->getEntityManager()->persist($reservation);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Supprime une réservation
     */
    public function remove(Reservations $reservation, bool $flush = false): void
    {
        $this->getEntityManager()->remove($reservation);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}