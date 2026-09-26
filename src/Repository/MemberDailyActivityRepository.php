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

    /**
     * Number of active members per day over the period, days without activity left out.
     *
     * @return array<string, int> keyed by Y-m-d
     */
    public function countActivePerDay(\DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        return $this->countPerDay(
            'SELECT day, COUNT(*) AS total FROM member_daily_activity WHERE day BETWEEN :first AND :last GROUP BY day',
            $firstDay,
            $lastDay,
        );
    }

    /**
     * Number of members active on a day who were also active the day before, per day over the period.
     *
     * @return array<string, int> keyed by Y-m-d
     */
    public function countReturningPerDay(\DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        return $this->countPerDay(
            'SELECT today.day, COUNT(*) AS total FROM member_daily_activity today
             INNER JOIN member_daily_activity previous ON previous.user_id = today.user_id AND previous.day = DATE_SUB(today.day, INTERVAL 1 DAY)
             WHERE today.day BETWEEN :first AND :last GROUP BY today.day',
            $firstDay,
            $lastDay,
        );
    }

    /** @return array<string, int> */
    private function countPerDay(string $sql, \DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue($sql, [
            'first' => $firstDay->format('Y-m-d'),
            'last' => $lastDay->format('Y-m-d'),
        ]);
        return array_map('intval', $rows);
    }
}
