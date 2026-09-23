<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Statistiques d'engagement des utilisateurs (rétention, actifs, inscriptions).
 *
 * Aucune table d'historique quotidien n'existe : seules les colonnes
 * User::createdAt, User::lastActivityAt (heartbeat, au plus 1x/min) et
 * User::lastDailyLoginAt sont disponibles. On en déduit :
 *
 * - "Dernière activité" = max(lastActivityAt, lastDailyLoginAt) (NULL ignorés).
 *
 * - Rétention glissante ("rolling retention") sur N jours, N ∈ {1, 7, 30} :
 *     cohorte  = utilisateurs inscrits depuis au moins N jours
 *                (createdAt <= maintenant - N jours) ;
 *     retenus  = membres de la cohorte dont la dernière activité est
 *                >= createdAt + N jours (revenus au moins N jours après leur inscription) ;
 *     taux     = retenus / cohorte (null si la cohorte est vide).
 *   Cette mesure est "au moins revenu une fois après J+N", pas "actif le jour J+N" :
 *   elle ne diminue jamais pour un utilisateur donné.
 *
 * - Actifs sur N jours : dernière activité >= maintenant - N jours.
 * - Nouveaux sur N jours : createdAt >= maintenant - N jours.
 *
 * Tout est calculé en une seule requête agrégée (SUM(CASE ...)).
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
