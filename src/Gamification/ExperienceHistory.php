<?php

namespace App\Gamification;

use App\Entity\User;
use App\Service\GamificationService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Historique d'XP (lecture du grand livre experience_award), réservé au propriétaire du profil.
 * Chaque ligne reçoit un libellé lisible dérivé de sa clé : « daily:2026-09-23 » → Connexion quotidienne,
 * « streak:… » → Bonus de série, « badge:pioneer_1 » → Badge Pionnier I, « level:… » → Niveau …
 */
class ExperienceHistory
{
    public const PREVIEW_SIZE = 30;
    public const PAGE_SIZE = 50;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Page de l'historique (plus récent d'abord) + totaux (nombre de lignes, somme de l'XP).
     *
     * @return array{rows: list<array{date: \DateTimeImmutable, key: string, label: string, icon: string, amount: int}>, count: int, total: int, page: int, pages: int, perPage: int}
     */
    public function page(User $user, int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        $totals = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM experience_award WHERE user_id = ?',
            [$user->getId()],
        ) ?: ['n' => 0, 'total' => 0];
        $count = (int) $totals['n'];
        $pages = max(1, (int) ceil($count / max(1, $perPage)));
        $page = max(1, min($page, $pages));

        $rows = [];
        if ($count > 0) {
            $result = $this->connection->executeQuery(
                'SELECT action_key, amount, created_at FROM experience_award WHERE user_id = ?
                 ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
                [$user->getId(), $perPage, ($page - 1) * $perPage],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
            );
            foreach ($result->iterateAssociative() as $row) {
                [$label, $icon] = self::describe($row['action_key']);
                $rows[] = [
                    'date' => new \DateTimeImmutable($row['created_at']),
                    'key' => $row['action_key'],
                    'label' => $label,
                    'icon' => $icon,
                    'amount' => (int) $row['amount'],
                ];
            }
        }

        return ['rows' => $rows, 'count' => $count, 'total' => (int) $totals['total'], 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /** @return array{0: string, 1: string} libellé lisible et icône d'une clé du grand livre */
    public static function describe(string $actionKey): array
    {
        [$type, $detail] = array_pad(explode(':', $actionKey, 2), 2, '');

        return match ($type) {
            'daily' => ['Connexion quotidienne', '📅'],
            'streak' => [sprintf('Bonus de série (%d jours)', GamificationService::STREAK_BONUS_EVERY), '🔥'],
            'badge' => ['Badge ' . (BadgeCatalog::get($detail)['name'] ?? $detail), BadgeCatalog::get($detail)['icon'] ?? '🏅'],
            'level' => ['Niveau ' . $detail, '⭐'],
            'solution' => ['Réponse choisie comme solution', '✅'],
            default => [ucfirst(str_replace(['_', ':'], [' ', ' — '], $actionKey)), '✨'],
        };
    }
}
