<?php

namespace App\Repository;

use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryPhotoComment>
 */
class GalleryPhotoCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryPhotoComment::class);
    }

    /**
     * Page de commentaires (du plus ancien au plus récent), auteurs chargés en une seule requête.
     *
     * @return GalleryPhotoComment[]
     */
    public function findPageForPhoto(GalleryPhoto $photo, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a', 'tb')
            ->join('c.author', 'a')
            ->leftJoin('a.titleBadge', 'tb') // titre des auteurs (pas de N+1)
            ->where('c.photo = :photo')
            ->setParameter('photo', $photo)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countForPhoto(GalleryPhoto $photo): int
    {
        return $this->count(['photo' => $photo]);
    }
}
