<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Thread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Charge TOUTES les catégories en une seule requête, avec leurs collections
     * « children » initialisées (fetch join). L'arbre complet est ensuite navigable
     * (parent / enfants / ancêtres) sans aucune requête supplémentaire.
     *
     * @return array<int, Category> indexé par id, trié par position
     */
    public function findAllAsTree(): array
    {
        /** @var Category[] $categories */
        $categories = $this->createQueryBuilder('c')
            ->addSelect('ch')
            ->leftJoin('c.children', 'ch')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('ch.position', 'ASC')
            ->addOrderBy('ch.id', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($categories as $category) {
            $indexed[$category->getId()] = $category;
        }

        return $indexed;
    }

    /**
     * Catégories racines (parent NULL) issues d'un arbre déjà chargé.
     *
     * @param array<int, Category> $tree
     *
     * @return list<Category>
     */
    public function rootsOf(array $tree): array
    {
        return array_values(array_filter($tree, static fn (Category $c): bool => $c->getParent() === null));
    }

    /**
     * Nombre de sujets propres à chaque catégorie, en une requête groupée.
     *
     * @return array<int, int> id catégorie => nombre de sujets
     */
    public function countThreadsByCategory(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(t.category) AS categoryId', 'COUNT(t.id) AS total')
            ->from(Thread::class, 't')
            ->groupBy('t.category')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            if ($row['categoryId'] !== null) {
                $counts[(int) $row['categoryId']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Totaux agrégés : sujets de la catégorie + de toutes ses sous-catégories.
     *
     * @param array<int, Category> $tree
     * @param array<int, int>      $ownCounts
     *
     * @return array<int, int>
     */
    public function aggregateThreadCounts(array $tree, array $ownCounts): array
    {
        $totals = [];
        foreach ($tree as $id => $category) {
            $total = $ownCounts[$id] ?? 0;
            foreach ($category->getDescendants() as $descendant) {
                $total += $ownCounts[$descendant->getId()] ?? 0;
            }
            $totals[$id] = $total;
        }

        return $totals;
    }

    /**
     * Dernière activité (dernière mise à jour, à défaut création, d'un sujet)
     * propre à chaque catégorie, en une requête groupée.
     *
     * @return array<int, \DateTimeImmutable> id catégorie => date
     */
    public function lastActivityByCategory(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(t.category) AS categoryId', 'MAX(COALESCE(t.updatedAt, t.createdAt)) AS lastActivity')
            ->from(Thread::class, 't')
            ->groupBy('t.category')
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        foreach ($rows as $row) {
            if ($row['categoryId'] !== null && $row['lastActivity'] !== null) {
                $dates[(int) $row['categoryId']] = new \DateTimeImmutable((string) $row['lastActivity']);
            }
        }

        return $dates;
    }

    /**
     * Dernière activité agrégée : la plus récente de la catégorie et de ses sous-catégories.
     *
     * @param array<int, Category>           $tree
     * @param array<int, \DateTimeImmutable> $ownDates
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function aggregateLastActivity(array $tree, array $ownDates): array
    {
        $latest = [];
        foreach ($tree as $id => $category) {
            $date = $ownDates[$id] ?? null;
            foreach ($category->getDescendants() as $descendant) {
                $candidate = $ownDates[$descendant->getId()] ?? null;
                if ($candidate !== null && ($date === null || $candidate > $date)) {
                    $date = $candidate;
                }
            }
            if ($date !== null) {
                $latest[$id] = $date;
            }
        }

        return $latest;
    }

    /**
     * Frères et sœurs d'une catégorie (même parent), hors elle-même, triés par position.
     *
     * @return list<Category>
     */
    public function findSiblings(?Category $parent, ?int $excludeId = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        if ($parent === null) {
            $qb->andWhere('c.parent IS NULL');
        } else {
            $qb->andWhere('c.parent = :parent')->setParameter('parent', $parent);
        }

        if ($excludeId !== null) {
            $qb->andWhere('c.id != :id')->setParameter('id', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }
}
