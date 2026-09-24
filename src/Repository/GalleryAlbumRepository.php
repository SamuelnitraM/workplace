<?php

namespace App\Repository;

use App\Entity\GalleryAlbum;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryAlbum>
 */
class GalleryAlbumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryAlbum::class);
    }

    /** @return GalleryAlbum[] albums of a member, most recent first, with their photos */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('p')
            ->leftJoin('a.photos', 'p')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
