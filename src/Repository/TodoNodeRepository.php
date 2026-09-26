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

    /**
     * Listes racines du groupe, avec leurs catégories, tâches et assignations (membres compris) chargées en une requête
     * (le template et TodoNodeVoter parcourent ces noeuds sans requête supplémentaire).
     *
     * $visibleTo non null (membre ni rédacteur ni lecteur) : seulement les listes contenant une tâche où il est assigné
     * ou a demandé à l'être ; le filtrage fin des catégories/tâches affichées se fait via TODO_VIEW.
     *
     * @return TodoNode[]
     */
    public function findGroupLists(Group $group, ?User $visibleTo = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->addSelect('c', 'i', 'ia', 'iau')
            ->leftJoin('n.children', 'c')
            ->leftJoin('c.children', 'i')
            ->leftJoin('i.assignments', 'ia')
            ->leftJoin('ia.user', 'iau')
            ->where('n.usergroup = :group')
            ->andWhere('n.type = :type')
            ->andWhere('n.parent IS NULL')
            ->setParameter('group', $group)
            ->setParameter('type', TodoNode::TYPE_LIST)
            ->orderBy('n.position', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('i.position', 'ASC');

        if ($visibleTo !== null) {
            // Tâche d'une catégorie de la liste où le membre est assigné (ou a demandé à l'être)
            $qb->andWhere(
                'EXISTS (SELECT assignment.id FROM App\Entity\TodoAssignment assignment
                INNER JOIN assignment.node task
                INNER JOIN task.parent category
                WHERE assignment.user = :user AND category.parent = n)'
            )->setParameter('user', $visibleTo);
        }

        return $qb->getQuery()->getResult();
    }
}
