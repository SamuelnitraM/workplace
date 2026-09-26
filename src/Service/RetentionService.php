<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Engagement figures over rolling periods (retention, active members, registrations), computed from the columns
 * User::createdAt, User::lastActivityAt (heartbeat, at most once a minute) and User::lastDailyLoginAt.
 * The day-by-day series of the dashboard charts come from member_daily_activity (App\Statistics\DailyActivityHistory).
 *
 * - Last activity = max(lastActivityAt, lastDailyLoginAt), NULL values ignored.
 *
 * - Rolling retention over N days, N in {1, 7, 30}:
 *     cohort   = members registered for at least N days (createdAt <= now - N days);
 *     retained = cohort members whose last activity is >= createdAt + N days (came back at least N days after registering);
 *     rate     = retained / cohort (NULL for an empty cohort).
 *   The measure is "came back at least once after day N", not "active on day N": it never decreases for a given member.
 *
 * - Active over N days: last activity >= now - N days.
 * - New over N days: createdAt >= now - N days.
 *
 * Everything is computed by a single aggregated query (SUM(CASE ...)).
 */
class RetentionService
{
    public const PERIODS = [1, 7, 30];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{
     *     total: int,
     *     new: array<int, int>,
     *     active: array<int, int>,
     *     retention: array<int, array{cohort: int, retained: int, rate: ?float}>
     * }
     */
    public function getUserStats(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $select = ['COUNT(u.id) AS total'];
        $qb = $this->em->createQueryBuilder()->from(User::class, 'u');

        foreach (self::PERIODS as $n) {
            $qb->setParameter('since'.$n, $now->modify(sprintf('-%d days', $n)));

            $select[] = sprintf('SUM(CASE WHEN u.createdAt >= :since%1$d THEN 1 ELSE 0 END) AS new%1$d', $n);
            $select[] = sprintf(
                'SUM(CASE WHEN u.lastActivityAt >= :since%1$d OR u.lastDailyLoginAt >= :since%1$d THEN 1 ELSE 0 END) AS active%1$d',
                $n,
            );
            $select[] = sprintf('SUM(CASE WHEN u.createdAt <= :since%1$d THEN 1 ELSE 0 END) AS cohort%1$d', $n);
            $select[] = sprintf(
                "SUM(CASE WHEN u.createdAt <= :since%1\$d AND (u.lastActivityAt >= DATE_ADD(u.createdAt, %1\$d, 'day') OR u.lastDailyLoginAt >= DATE_ADD(u.createdAt, %1\$d, 'day')) THEN 1 ELSE 0 END) AS retained%1\$d",
                $n,
            );
        }

        $row = $qb->select(implode(', ', $select))->getQuery()->getSingleResult();

        $stats = ['total' => (int) $row['total'], 'new' => [], 'active' => [], 'retention' => []];
        foreach (self::PERIODS as $n) {
            $cohort = (int) $row['cohort'.$n];
            $retained = (int) $row['retained'.$n];
            $stats['new'][$n] = (int) $row['new'.$n];
            $stats['active'][$n] = (int) $row['active'.$n];
            $stats['retention'][$n] = [
                'cohort' => $cohort,
                'retained' => $retained,
                'rate' => $cohort > 0 ? round($retained * 100 / $cohort, 1) : null,
            ];
        }

        return $stats;
    }
}
