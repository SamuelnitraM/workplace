<?php

namespace App\Statistics;

use App\Repository\MemberDailyActivityRepository;
use App\Repository\UserRepository;

/**
 * Day-by-day series of the admin dashboard charts, built from member_daily_activity and user.created_at:
 * active members, registrations and next-day retention (share of the members active the day before who came back).
 */
class DailyActivityHistory
{
    public const DAYS = 30;

    public function __construct(
        private readonly MemberDailyActivityRepository $dailyActivityRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * One entry per day, oldest first, today included.
     *
     * @return list<array{date: \DateTimeImmutable, active: int, registrations: int, returning: int, previousActive: int, retention: ?float}>
     */
    public function lastDays(\DateTimeImmutable $today, int $days = self::DAYS): array
    {
        $lastDay = $today->setTime(0, 0);
        $firstDay = $lastDay->modify(sprintf('-%d days', $days - 1));
        $active = $this->dailyActivityRepository->countActivePerDay($firstDay->modify('-1 day'), $lastDay);
        $returning = $this->dailyActivityRepository->countReturningPerDay($firstDay, $lastDay);
        $registrations = $this->userRepository->countRegistrationsPerDay($firstDay);
        $history = [];
        for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $previousActive = $active[$day->modify('-1 day')->format('Y-m-d')] ?? 0;
            $returningCount = $returning[$key] ?? 0;
            $history[] = [
                'date' => $day,
                'active' => $active[$key] ?? 0,
                'registrations' => $registrations[$key] ?? 0,
                'returning' => $returningCount,
                'previousActive' => $previousActive,
                'retention' => $previousActive > 0 ? round($returningCount * 100 / $previousActive, 1) : null,
            ];
        }
        return $history;
    }

    /**
     * Charts of the dashboard: daily active members, registrations and next-day retention.
     *
     * @return list<BarChart>
     */
    public function charts(\DateTimeImmutable $today): array
    {
        $history = $this->lastDays($today);
        $point = static fn (array $day, ?float $value, string $detail): array => [
            'label' => $day['date']->format('d/m'),
            'value' => $value,
            'detail' => $day['date']->format('d/m/Y') . ' : ' . $detail,
        ];
        return [
            new BarChart(
                'chart-active',
                'Membres actifs par jour',
                'Membres ayant utilisé le site au moins une fois dans la journée.',
                array_map(static fn (array $day): array => $point($day, (float) $day['active'], sprintf('%d membre%s actif%2$s', $day['active'], $day['active'] > 1 ? 's' : '')), $history),
            ),
            new BarChart(
                'chart-registrations',
                'Nouvelles inscriptions',
                'Comptes créés chaque jour.',
                array_map(static fn (array $day): array => $point($day, (float) $day['registrations'], sprintf('%d inscription%s', $day['registrations'], $day['registrations'] > 1 ? 's' : '')), $history),
            ),
            new BarChart(
                'chart-retention',
                'Rétention d\'un jour sur l\'autre',
                'Part des membres actifs la veille qui sont revenus ce jour-là (aucune barre : personne n\'était actif la veille).',
                array_map(static fn (array $day): array => $point(
                    $day,
                    $day['retention'],
                    $day['retention'] === null ? 'aucun membre actif la veille' : sprintf('%s %% (%d sur %d)', number_format($day['retention'], 1, ',', ' '), $day['returning'], $day['previousActive']),
                ), $history),
                '%',
                100.0,
            ),
        ];
    }
}
