<?php

namespace App\Repository;

use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoComment;
use App\Entity\GalleryPhotoLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GalleryPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryPhoto::class);
    }

    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC']);
    }

    public function findVisibleByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner, 'isVisible' => true], ['createdAt' => 'DESC']);
    }

    /**
     * Dernières photos visibles de toute la communauté (vitrine de l'accueil), propriétaire chargé dans la même requête.
     *
     * @return GalleryPhoto[]
     */
    public function findLatestVisible(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('o')
            ->innerJoin('p.owner', 'o')
            ->where('p.isVisible = true')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compteurs de likes / commentaires pour une liste de photos, en deux requêtes agrégées (pas de N+1).
     *
     * @param GalleryPhoto[] $photos
     * @return array<int, array{likes: int, comments: int}> indexé par id de photo
     */
    public function getStatsForPhotos(array $photos): array
    {
        $ids = array_values(array_filter(array_map(static fn (GalleryPhoto $photo) => $photo->getId(), $photos)));
        $stats = array_fill_keys($ids, ['likes' => 0, 'comments' => 0]);
        if ($ids === []) {
            return $stats;
        }

        $em = $this->getEntityManager();
        foreach ([GalleryPhotoLike::class => 'likes', GalleryPhotoComment::class => 'comments'] as $class => $key) {
            $rows = $em->createQueryBuilder()
                ->select('IDENTITY(x.photo) AS photoId', 'COUNT(x.id) AS total')
                ->from($class, 'x')
                ->where('x.photo IN (:ids)')
                ->setParameter('ids', $ids)
                ->groupBy('x.photo')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $stats[(int) $row['photoId']][$key] = (int) $row['total'];
            }
        }

        return $stats;
    }
}
