<?php

namespace App\Repository;

use App\Entity\Group;
use App\Entity\TodoNode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TodoNode>
 */
class TodoNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TodoNode::class);
    }

    public function findGroupListsForMember(Group $group, User $user): array
    {
        // Récupère les listes qui ont au moins une tâche assignée à cet user
        return $this->createQueryBuilder('n')
            ->where('n.usergroup = :group')
            ->andWhere('n.type = :type')
            ->andWhere('n.parent IS NULL')
            ->andWhere(
                'EXISTS (SELECT i FROM App\Entity\TodoNode i 
                WHERE i.parent IS NOT NULL 
                AND i.usergroup = :group 
                AND i.assignedTo = :user)'
            )
            ->setParameter('group', $group)
            ->setParameter('type', TodoNode::TYPE_LIST)
            ->setParameter('user', $user)
            ->orderBy('n.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return TodoNode[] Returns an array of TodoNode objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('t.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?TodoNode
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
