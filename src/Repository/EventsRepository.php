<?php

namespace App\Repository;

use App\Entity\Events;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Events>
 */
class EventsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Events::class);
    }

//    /**
//     * @return Events[]
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('e')
//            ->andWhere('e.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('e.id', 'ASC')
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    /**
//     * @return Events|null
//     */
//    public function findOneBySomeField($value): ?Events
//    {
//        return $this->createQueryBuilder('e')
//            ->andWhere('e.someField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNull()
//        ;
//    }
}
