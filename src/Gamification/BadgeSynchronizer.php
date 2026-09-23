<?php

namespace App\Gamification;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Projection idempotente du catalogue (BadgeCatalog) dans la table `badge` :
 * upsert par code, suppression des codes obsolètes (et de leurs user_badge).
 * L'XP déjà créditée par un badge supprimé reste acquise (grand livre inchangé).
 */
class BadgeSynchronizer
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{inserted: list<string>, updated: list<string>, unchanged: list<string>, deleted: array<string, int>} */
    public function sync(bool $dryRun = false): array
    {
        $result = ['inserted' => [], 'updated' => [], 'unchanged' => [], 'deleted' => []];
        $existing = [];
        foreach ($this->connection->fetchAllAssociative('SELECT code, name, category, description, icon, hidden, xp_reward FROM badge') as $row) {
            $existing[$row['code']] = $row;
        }

        $this->connection->transactional(function (Connection $connection) use (&$result, $existing, $dryRun): void {
            foreach (BadgeCatalog::all() as $code => $badge) {
                $values = [
                    'name' => $badge['name'],
                    'category' => $badge['category'],
                    'description' => $badge['description'],
                    'icon' => $badge['icon'],
                    'hidden' => 0,
                    'xp_reward' => $badge['xp'],
                ];
                $current = $existing[$code] ?? null;
                if ($current === null) {
                    $result['inserted'][] = $code;
                    if (!$dryRun) {
                        $connection->insert('badge', ['code' => $code, 'hidden_description' => ''] + $values);
                    }
                    continue;
                }
                $changed = array_filter($values, static fn ($value, string $column) => (string) $current[$column] !== (string) $value, ARRAY_FILTER_USE_BOTH);
                if (!$changed) {
                    $result['unchanged'][] = $code;
                    continue;
                }
                $result['updated'][] = $code;
                if (!$dryRun) {
                    $connection->update('badge', $changed, ['code' => $code]);
                }
            }

            $obsolete = array_values(array_diff(array_keys($existing), BadgeCatalog::codes()));
            if ($obsolete) {
                $result['deleted'] = array_map('intval', $connection->fetchAllKeyValue(
                    'SELECT b.code, COUNT(ub.id) FROM badge b LEFT JOIN user_badge ub ON ub.badge_id = b.id WHERE b.code IN (?) GROUP BY b.code',
                    [$obsolete],
                    [ArrayParameterType::STRING],
                ));
                if (!$dryRun) {
                    // Titres de profil portant un badge supprimé : retirés (la FK ON DELETE SET NULL le ferait aussi)
                    $connection->executeStatement(
                        'UPDATE `user` u INNER JOIN badge b ON b.id = u.title_badge_id SET u.title_badge_id = NULL WHERE b.code IN (?)',
                        [$obsolete],
                        [ArrayParameterType::STRING],
                    );
                    $connection->executeStatement('DELETE ub FROM user_badge ub INNER JOIN badge b ON b.id = ub.badge_id WHERE b.code IN (?)', [$obsolete], [ArrayParameterType::STRING]);
                    $connection->executeStatement('DELETE FROM badge WHERE code IN (?)', [$obsolete], [ArrayParameterType::STRING]);
                }
            }

            // Filet de sécurité : un titre doit être un badge débloqué par le membre (user_badge)
            if (!$dryRun) {
                $connection->executeStatement(
                    'UPDATE `user` u SET u.title_badge_id = NULL WHERE u.title_badge_id IS NOT NULL
                     AND NOT EXISTS (SELECT 1 FROM user_badge ub WHERE ub.user_id = u.id AND ub.badge_id = u.title_badge_id)',
                );
            }
        });

        return $result;
    }
}
