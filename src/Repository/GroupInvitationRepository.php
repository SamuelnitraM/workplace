<?php

namespace App\Repository;

use App\Entity\GroupInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupInvitation>
 */
class GroupInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupInvitation::class);
    }

    /**
     * Pending invitations received by a member, most recent first, group and inviter loaded.
     *
     * @return GroupInvitation[]
     */
    public function findPendingFor(User $member): array
    {
        return $this->createQueryBuilder('invitation')
            ->addSelect('grp', 'inviter')
            ->innerJoin('invitation.usergroup', 'grp')
            ->leftJoin('invitation.invitedBy', 'inviter')
            ->where('invitation.invitedUser = :member')
            ->andWhere("invitation.status = 'pending'")
            ->setParameter('member', $member)
            ->orderBy('invitation.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
