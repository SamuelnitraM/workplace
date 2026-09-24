<?php

namespace App\Repository;

use App\Army\BattleSize;
use App\Entity\ArmyList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArmyList>
 */
class ArmyListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArmyList::class);
    }

    public function findPublicByOwner(object $owner): array
    {
        return $this->findBy(['owner' => $owner, 'isPublic' => true], ['createdAt' => 'DESC']);
    }

    /**
     * Public lists matching the Explorer filters, most recent first (owner loaded with the lists).
     */
    public function createPublicSearchQuery(string $faction, string $detachment, ?BattleSize $battleSize, string $search): Query
    {
        $queryBuilder = $this->createQueryBuilder('l')
            ->addSelect('o')
            ->innerJoin('l.owner', 'o')
            ->where('l.isPublic = true')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC');
        if ($faction !== '') {
            $queryBuilder->andWhere('l.faction = :faction')->setParameter('faction', $faction);
        }
        if ($detachment !== '') {
            $queryBuilder->andWhere('l.detachment = :detachment')->setParameter('detachment', $detachment);
        }
        if ($battleSize !== null) {
            $queryBuilder->andWhere('l.battleSize = :battleSize')->setParameter('battleSize', $battleSize);
        }
        if ($search !== '') {
            $queryBuilder->andWhere('l.name LIKE :search OR o.username LIKE :search')
                ->setParameter('search', '%' . addcslashes($search, '%_\\') . '%');
        }
        return $queryBuilder->getQuery();
    }
}
