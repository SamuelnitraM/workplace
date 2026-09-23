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
     * Listes racines du groupe, avec leurs catégories, tâches et utilisateurs assignés chargés en une requête
     * (le template et TodoNodeVoter parcourent ces noeuds sans requête supplémentaire).
     *
     * $visibleTo non null (membre non rédacteur) : seulement les listes contenant une catégorie ou une tâche
     * qui lui est assignée ; le filtrage fin des catégories/tâches affichées se fait via TODO_VIEW.
     *
     * @return TodoNode[]
     */
    public function findGroupLists(Group $group, ?User $visibleTo = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->addSelect('c', 'ca', 'i', 'ia')
            ->leftJoin('n.children', 'c')
            ->leftJoin('c.assignedTo', 'ca')
            ->leftJoin('c.children', 'i')
            ->leftJoin('i.assignedTo', 'ia')
            ->where('n.usergroup = :group')
            ->andWhere('n.type = :type')
            ->andWhere('n.parent IS NULL')
            ->setParameter('group', $group)
            ->setParameter('type', TodoNode::TYPE_LIST)
            ->orderBy('n.position', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('i.position', 'ASC');

        if ($visibleTo !== null) {
            // Catégorie assignée ou tâche assignée directement sous la liste (x.parent = n),
            // ou tâche assignée sous une catégorie de la liste (p.parent = n)
            $qb->andWhere(
                'EXISTS (SELECT x.id FROM App\Entity\TodoNode x
                LEFT JOIN x.parent p
                WHERE x.assignedTo = :user
                AND (x.parent = n OR p.parent = n))'
            )->setParameter('user', $visibleTo);
        }

        return $qb->getQuery()->getResult();
    }
}
