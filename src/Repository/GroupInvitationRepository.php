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

    //    /**
    //     * @return GroupInvitation[] Returns an array of GroupInvitation objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('g')
    //            ->andWhere('g.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('g.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?GroupInvitation
    //    {
    //        return $this->createQueryBuilder('g')
    //            ->andWhere('g.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

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
