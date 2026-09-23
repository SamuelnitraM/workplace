<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Classement général (/classement).
 *
 * Chaque critère est une sous-requête agrégée « (user_id, metric) » ; la page, le nombre de classés
 * et le rang du membre connecté en sont dérivés (ordre : metric DESC, puis id ASC = ancienneté).
 * Les utilisateurs de la page sont ensuite chargés en UNE requête (pas de N+1).
 *
 * Critères (clé = valeur du paramètre d'URL « tri ») :
 *  - niveau  : XP totale (détermine le niveau) — tous les membres, même à 0 XP
 *  - semaine : XP gagnée depuis lundi 00:00 (Europe/Paris) — membres avec au moins 1 XP
 *  - mois    : XP gagnée depuis le 1er du mois 00:00 (Europe/Paris) — membres avec au moins 1 XP
 *  - badges  : nombre de badges obtenus — membres avec au moins 1 badge
 *  - serie   : série de connexions en cours, uniquement si elle est encore vivante (protection de
 *              3 jours d'absence, cf. GamificationService::streakAliveThreshold())
 *  - sujets  : nombre de sujets créés sur le forum — au moins 1
 *  - votes   : nombre de votes (positifs + « aide ») reçus sur ses réponses — au moins 1
 */
class LeaderboardService
{
    public const DEFAULT_SORT = 'niveau';
    public const PER_PAGE = 25;
    /** Nombre maximal de membres affichés dans le classement (pagination). */
    public const MAX_RANKED = 100;

    /** @var array<string, array{label: string, icon: string, description: string}> */
    public const SORTS = [
        'niveau' => ['label' => 'Plus haut niveau', 'icon' => '⭐', 'description' => 'Classement par expérience totale (qui détermine le niveau).'],
        'semaine' => ['label' => 'XP de la semaine', 'icon' => '📅', 'description' => 'Expérience gagnée depuis lundi (heure de Paris). Seuls les membres ayant gagné de l\'XP apparaissent.'],
        'mois' => ['label' => 'XP du mois', 'icon' => '🗓️', 'description' => 'Expérience gagnée depuis le 1er du mois (heure de Paris). Seuls les membres ayant gagné de l\'XP apparaissent.'],
        'badges' => ['label' => 'Plus de badges', 'icon' => '🏅', 'description' => 'Nombre de badges obtenus.'],
        'serie' => ['label' => 'Meilleure série', 'icon' => '🔥', 'description' => 'Série de connexions quotidiennes en cours (une série est perdue après plus de 3 jours d\'absence consécutifs).'],
        'sujets' => ['label' => 'Plus de sujets', 'icon' => '📝', 'description' => 'Nombre de sujets créés sur le forum.'],
        'votes' => ['label' => 'Plus de votes reçus', 'icon' => '👍', 'description' => 'Votes (positifs et « aide ») reçus sur les réponses du forum.'],
    ];

    public function __construct(private EntityManagerInterface $entityManager) {}

    public static function normalizeSort(?string $sort): string
    {
        return $sort !== null && isset(self::SORTS[$sort]) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * @return array{
     *   sort: string, page: int, pages: int, total: int,
     *   rows: list<array{rank: int, user: User, metric: int}>,
     *   me: ?array{rank: int, metric: int, onPage: bool}
     * }
     */
    public function getLeaderboard(string $sort, int $page, ?User $viewer, ?\DateTimeImmutable $now = null): array
    {
        $sort = self::normalizeSort($sort);
        [$metricSql, $params] = $this->metricQuery($sort, $now ?? new \DateTimeImmutable());
        $connection = $this->entityManager->getConnection();

        $total = min(self::MAX_RANKED, (int) $connection->fetchOne("SELECT COUNT(*) FROM ($metricSql) m", $params));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::PER_PAGE;
        $limit = max(0, min(self::PER_PAGE, $total - $offset));

        $ranking = $limit > 0
            ? $connection->fetchAllAssociative(
                sprintf('SELECT m.user_id, m.metric FROM (%s) m ORDER BY m.metric DESC, m.user_id ASC LIMIT %d OFFSET %d', $metricSql, $limit, $offset),
                $params,
            )
            : [];

        $users = $this->loadUsers(array_map(static fn (array $row) => (int) $row['user_id'], $ranking));
        $rows = [];
        foreach ($ranking as $index => $row) {
            $user = $users[(int) $row['user_id']] ?? null;
            if ($user !== null) {
                $rows[] = ['rank' => $offset + $index + 1, 'user' => $user, 'metric' => (int) $row['metric']];
            }
        }

        $me = null;
        if ($viewer?->getId() !== null) {
            $metric = $connection->fetchOne("SELECT m.metric FROM ($metricSql) m WHERE m.user_id = ?", [...$params, $viewer->getId()]);
            if ($metric !== false) {
                $rank = 1 + (int) $connection->fetchOne(
                    "SELECT COUNT(*) FROM ($metricSql) m WHERE m.metric > ? OR (m.metric = ? AND m.user_id < ?)",
                    [...$params, (int) $metric, (int) $metric, $viewer->getId()],
                );
                $me = ['rank' => $rank, 'metric' => (int) $metric, 'onPage' => $rank > $offset && $rank <= $offset + $limit];
            }
        }

        return ['sort' => $sort, 'page' => $page, 'pages' => $pages, 'total' => $total, 'rows' => $rows, 'me' => $me];
    }

    /** @return array{0: string, 1: list<mixed>} sous-requête (user_id, metric) et ses paramètres positionnels */
    private function metricQuery(string $sort, \DateTimeImmutable $now): array
    {
        $paris = $now->setTimezone(new \DateTimeZone(GamificationService::TIMEZONE));

        return match ($sort) {
            'semaine' => [self::AWARDS_SINCE, [self::dbDateTime($paris->modify('monday this week')->setTime(0, 0))]],
            'mois' => [self::AWARDS_SINCE, [self::dbDateTime($paris->modify('first day of this month')->setTime(0, 0))]],
            'badges' => ['SELECT ub.user_id, COUNT(*) AS metric FROM user_badge ub GROUP BY ub.user_id', []],
            'serie' => [
                'SELECT u.id AS user_id, u.login_streak AS metric FROM `user` u WHERE u.login_streak > 0 AND u.last_daily_login_at >= ?',
                [GamificationService::streakAliveThreshold($now)],
            ],
            'sujets' => ['SELECT t.author_id AS user_id, COUNT(*) AS metric FROM thread t GROUP BY t.author_id', []],
            'votes' => ['SELECT p.author_id AS user_id, COUNT(*) AS metric FROM post_vote v INNER JOIN post p ON p.id = v.post_id GROUP BY p.author_id', []],
            default => ['SELECT u.id AS user_id, u.experience AS metric FROM `user` u', []],
        };
    }

    private const AWARDS_SINCE = 'SELECT ea.user_id, SUM(ea.amount) AS metric FROM experience_award ea WHERE ea.created_at >= ? GROUP BY ea.user_id HAVING SUM(ea.amount) > 0';

    /**
     * @param list<int> $ids
     * @return array<int, User>
     */
    private function loadUsers(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER);

        // Titre affiché à côté du pseudo (User::$titleBadge) : chargé dans la même requête s'il existe
        $metadata = $this->entityManager->getClassMetadata(User::class);
        if ($metadata->hasAssociation('titleBadge')) {
            $qb->addSelect('title')->leftJoin('u.titleBadge', 'title');
        }

        $users = [];
        foreach ($qb->getQuery()->getResult() as $user) {
            $users[$user->getId()] = $user;
        }

        return $users;
    }

    private static function dbDateTime(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
