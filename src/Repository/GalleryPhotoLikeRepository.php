<?php

namespace App\Repository;

use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryPhotoLike>
 */
class GalleryPhotoLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryPhotoLike::class);
    }

    public function findOneByPhotoAndUser(GalleryPhoto $photo, User $user): ?GalleryPhotoLike
    {
        return $this->findOneBy(['photo' => $photo, 'user' => $user]);
    }

    public function countForPhoto(GalleryPhoto $photo): int
    {
        return $this->count(['photo' => $photo]);
    }
}
