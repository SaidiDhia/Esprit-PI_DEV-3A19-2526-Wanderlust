<?php

namespace App\Repository;

use App\Entity\Activities;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activities>
 */
class ActivitiesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activities::class);
    }

    /**
     * Récupère uniquement les activités acceptées avec pagination
     */
    public function findAcceptedActivities(int $page = 1, int $limit = 9): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', 'accepte')
            ->orderBy('a.date_creation', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre total d'activités acceptées
     */
    public function countAcceptedActivities(): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', 'accepte')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère uniquement les activités en attente avec pagination
     */
    public function findPendingActivities(int $page = 1, int $limit = 9): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', 'en_attente')
            ->orderBy('a.date_creation', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre total d'activités en attente
     */
    public function countPendingActivities(): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', 'en_attente')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sauvegarde une activité
     */
    /**
     * Récupère toutes les activités avec pagination
     */
    public function findAllWithPagination(int $page = 1, int $limit = 9): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('a')
            ->orderBy('a.date_creation', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre total d'activités
     */
    public function countAll(): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(Activities $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Supprime une activité
     */
    public function remove(Activities $activity): void
    {
        $this->getEntityManager()->remove($activity);
        $this->getEntityManager()->flush();
    }
}