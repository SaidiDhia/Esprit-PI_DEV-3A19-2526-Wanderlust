<?php

namespace App\Repository;

use App\Entity\DynamicPricingRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DynamicPricingRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DynamicPricingRule::class);
    }

    public function save(DynamicPricingRule $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DynamicPricingRule $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Trouver une règle par type d'événement
     */
    public function findByEventType(string $eventType): ?DynamicPricingRule
    {
        return $this->createQueryBuilder('r')
            ->where('r.eventType = :eventType')
            ->andWhere('r.isActive = :active')
            ->setParameter('eventType', $eventType)
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouver la règle par défaut
     */
    public function findDefaultRule(): ?DynamicPricingRule
    {
        return $this->createQueryBuilder('r')
            ->where('r.eventType = :eventType')
            ->andWhere('r.isActive = :active')
            ->setParameter('eventType', 'default')
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupérer toutes les règles actives
     */
    public function findActiveRules(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('r.eventType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupérer les types d'événements disponibles
     */
    public function getEventTypes(): array
    {
        return $this->createQueryBuilder('r')
            ->select('DISTINCT r.eventType')
            ->where('r.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
