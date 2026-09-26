<?php

namespace App\Repository;

use App\Entity\MemberDailyActivity;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberDailyActivity>
 */
class MemberDailyActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberDailyActivity::class);
    }

    /** Records that the member was active on the given day; a day already recorded is left as it is. */
    public function record(User $user, \DateTimeImmutable $day): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO member_daily_activity (user_id, day) VALUES (:user, :day)',
            ['user' => $user->getId(), 'day' => $day->format('Y-m-d')]
        );
    }
}
