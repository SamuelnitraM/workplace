<?php

namespace App\Gamification;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Rareté des badges : part des membres ayant débloqué chaque badge.
 * UNE requête groupée (COUNT user_badge par badge + nombre total de membres), mise en cache 5 minutes
 * (valeur indicative : un badge obtenu entre-temps n'apparaît qu'à l'expiration du cache).
 *
 * Libellés : Commun ≥ 50 %, Peu commun 20–50 %, Rare 5–20 %, Épique 1–5 %, Légendaire < 1 %.
 */
class BadgeRarity
{
    private const CACHE_KEY = 'gamification.badge_rarity';
    private const CACHE_TTL = 300;

    /** Seuils (pourcentage minimal) => [clé CSS, libellé], du plus commun au plus rare. */
    private const LEVELS = [
        [50.0, 'common', 'Commun'],
        [20.0, 'uncommon', 'Peu commun'],
        [5.0, 'rare', 'Rare'],
        [1.0, 'epic', 'Épique'],
        [0.0, 'legendary', 'Légendaire'],
    ];

    public function __construct(
        private Connection $connection,
        private CacheInterface $cache,
    ) {}

    /**
     * @return array<string, array{holders: int, members: int, percent: float, percentLabel: string, key: string, label: string}> code => rareté
     */
    public function all(): array
    {
        $counts = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetchCounts();
        });

        $members = (int) ($counts['members'] ?? 0);
        $rarity = [];
        foreach (BadgeCatalog::codes() as $code) {
            $rarity[$code] = self::describe((int) ($counts['holders'][$code] ?? 0), $members);
        }

        return $rarity;
    }

    /** Oublie le cache (tests, commande de synchronisation). */
    public function clear(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /** @return array{holders: array<string, int>, members: int} */
    private function fetchCounts(): array
    {
        $holders = [];
        $members = 0;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.code, COUNT(ub.id) AS holders, (SELECT COUNT(*) FROM `user`) AS members
             FROM badge b LEFT JOIN user_badge ub ON ub.badge_id = b.id
             GROUP BY b.id, b.code',
        );
        foreach ($rows as $row) {
            $holders[$row['code']] = (int) $row['holders'];
            $members = (int) $row['members'];
        }

        return ['holders' => $holders, 'members' => $members];
    }

    /** @return array{holders: int, members: int, percent: float, percentLabel: string, key: string, label: string} */
    public static function describe(int $holders, int $members): array
    {
        $percent = $members > 0 ? min(100.0, $holders / $members * 100) : 0.0;
        [, $key, $label] = self::LEVELS[array_key_last(self::LEVELS)];
        foreach (self::LEVELS as [$min, $levelKey, $levelLabel]) {
            if ($percent >= $min) {
                [$key, $label] = [$levelKey, $levelLabel];
                break;
            }
        }

        // Une décimale sous 10 % (0,4 %), entier au-delà ; jamais « 0 % » si au moins un membre l'a
        $display = $percent < 10 ? round($percent, 1) : round($percent);
        if ($holders > 0 && $display <= 0) {
            $display = 0.1;
        }
        $percentLabel = rtrim(rtrim(number_format($display, 1, ',', ''), '0'), ',');

        return [
            'holders' => $holders,
            'members' => $members,
            'percent' => $percent,
            'percentLabel' => $percentLabel,
            'key' => $key,
            'label' => $label,
        ];
    }
}
