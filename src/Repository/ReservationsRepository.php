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

    public function findByEvent(int $eventId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.event = :id')
            ->setParameter('id', $eventId)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(Reservations $r, bool $flush = false): void
    {
        $this->getEntityManager()->persist($r);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function remove(Reservations $r, bool $flush = false): void
    {
        $this->getEntityManager()->remove($r);
        if ($flush) $this->getEntityManager()->flush();
    }
}