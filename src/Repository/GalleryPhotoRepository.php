<?php

namespace App\Repository;

use App\Entity\GalleryPhoto;
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
}