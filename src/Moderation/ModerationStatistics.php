<?php

namespace App\Moderation;

use App\Repository\ReportRepository;

/** Figures of the moderation block of the admin dashboard: handling time and summary of the reports. */
class ModerationStatistics
{
    public const RECENT_DAYS = 30;

    public function __construct(private readonly ReportRepository $reportRepository)
    {
    }

    /**
     * @return array{
     *     averageHandling: ?float,
     *     recentAverageHandling: ?float,
     *     reasons: list<array{label: string, count: int}>,
     *     targetTypes: list<array{label: string, count: int}>,
     *     resolutions: list<array{label: string, count: int, severity: string}>
     * }
     */
    public function summary(\DateTimeImmutable $now): array
    {
        $reasons = [];
        foreach ($this->reportRepository->countGroupedBy('reason') as $value => $count) {
            $reasons[] = ['label' => ReportReason::tryFrom((string) $value)?->label() ?? (string) $value, 'count' => $count];
        }
        $targetTypes = [];
        foreach ($this->reportRepository->countGroupedBy('target_type') as $value => $count) {
            $targetTypes[] = ['label' => ReportTargetType::tryFrom((string) $value)?->label() ?? (string) $value, 'count' => $count];
        }
        $resolutions = [];
        foreach ($this->reportRepository->countResolutions() as $value => $count) {
            $resolution = ReportResolution::tryFrom((string) $value);
            if ($resolution !== null) {
                $resolutions[] = ['label' => $resolution->label(), 'count' => $count, 'severity' => $resolution->severity()];
            }
        }
        return [
            'averageHandling' => $this->reportRepository->averageHandlingSeconds(),
            'recentAverageHandling' => $this->reportRepository->averageHandlingSeconds($now->modify(sprintf('-%d days', self::RECENT_DAYS))),
            'reasons' => $reasons,
            'targetTypes' => $targetTypes,
            'resolutions' => $resolutions,
        ];
    }

    /** Readable duration: « 3 min », « 5 h 20 », « 2 j 4 h ». */
    public static function formatDuration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $minutes = (int) round($seconds / 60);
        return match (true) {
            $minutes < 1 => 'moins d\'1 min',
            $minutes < 60 => sprintf('%d min', $minutes),
            $minutes < 1440 => sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60),
            default => sprintf('%d j %d h', intdiv($minutes, 1440), intdiv($minutes % 1440, 60)),
        };
    }
}
