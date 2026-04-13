<?php

namespace App\Repository;

use App\Entity\EventImages;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventImages>
 */
class EventImagesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventImages::class);
    }

    public function save(EventImages $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EventImages $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Trouve toutes les images d'un événement
     */
    public function findByEvent(int $eventId): array
    {
        return $this->createQueryBuilder('ei')
            ->where('ei.event = :eventId')
            ->setParameter('eventId', $eventId)
            ->orderBy('ei.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre d'images d'un événement
     */
    public function countByEvent(int $eventId): int
    {
        return (int) $this->createQueryBuilder('ei')
            ->select('COUNT(ei.id)')
            ->where('ei.event = :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}