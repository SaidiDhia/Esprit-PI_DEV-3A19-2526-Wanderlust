<?php

namespace App\Repository;

use App\Entity\Reservations;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservations>
 */
class ReservationsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservations::class);
    }

    public function save(Reservations $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Reservations $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Trouver les réservations par événement
     */
    public function findByEvent($eventId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.event = :eventId')
            ->setParameter('eventId', $eventId)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les réservations par email
     */
    public function findByEmail($email): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.email = :email')
            ->setParameter('email', $email)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les réservations par statut
     */
    public function findByStatut($statut): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les réservations non payées
     */
    public function findUnpaid(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.montantPaye IS NULL OR r.montantPaye < r.prixTotal')
            ->andWhere('r.statut != :annulee')
            ->setParameter('annulee', Reservations::STATUT_ANNULEE)
            ->orderBy('r.dateCreation', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compter les réservations par événement
     */
    public function countByEvent($eventId): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.event = :eventId')
            ->andWhere('r.statut != :annulee')
            ->setParameter('eventId', $eventId)
            ->setParameter('annulee', Reservations::STATUT_ANNULEE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calculer le total des places réservées par événement
     */
    public function getTotalPlacesByEvent($eventId): int
    {
        return $this->createQueryBuilder('r')
            ->select('SUM(r.nombrePersonnes)')
            ->where('r.event = :eventId')
            ->andWhere('r.statut != :annulee')
            ->setParameter('eventId', $eventId)
            ->setParameter('annulee', Reservations::STATUT_ANNULEE)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Trouver les réservations récentes
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
     * Rechercher des réservations par nom ou email
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.nomComplet LIKE :query')
            ->orWhere('r.email LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouver les réservations par plage de dates
     */
    public function findByDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.dateReservation BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('r.dateReservation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculer le revenu total par période
     */
    public function getTotalRevenue(\DateTimeInterface $startDate, \DateTimeInterface $endDate): ?float
    {
        $result = $this->createQueryBuilder('r')
            ->select('SUM(r.prixTotal)')
            ->where('r.dateReservation BETWEEN :startDate AND :endDate')
            ->andWhere('r.statut != :annulee')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('annulee', Reservations::STATUT_ANNULEE)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (float) $result : null;
    }
}
