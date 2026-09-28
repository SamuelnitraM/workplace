<?php

namespace App\Repository;

use App\Entity\Appeal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appeal>
 */
class AppealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appeal::class);
    }

    public function countPending(): int
    {
        return $this->count(['status' => Appeal::STATUS_PENDING]);
    }

    public function findPendingFor(User $member): ?Appeal
    {
        return $this->findOneBy(['member' => $member, 'status' => Appeal::STATUS_PENDING]);
    }

    /** @return Appeal[] most recent first */
    public function findForMember(User $member): array
    {
        return $this->findBy(['member' => $member], ['createdAt' => 'DESC']);
    }
}
