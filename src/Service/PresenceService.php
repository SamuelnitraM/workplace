<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Présence « en ligne » basée sur le heartbeat (User::lastActivityAt).
 *
 * Le navigateur envoie un heartbeat toutes les 30 s tant qu'un onglet du site est visible
 * (et immédiatement quand l'onglet redevient visible). Un utilisateur est considéré
 * connecté si son dernier heartbeat date de moins de ONLINE_WINDOW_SECONDS.
 * La déconnexion le retire immédiatement (voir markOffline()).
 */
class PresenceService
{
    /** Fréquence du heartbeat côté navigateur (ms), transmise au contrôleur Stimulus. */
    public const HEARTBEAT_INTERVAL_MS = 30000;

    /** Écriture en base au plus une fois par intervalle (tolérance pour la gigue réseau). */
    public const WRITE_THROTTLE_SECONDS = 25;

    /** Au-delà de ce délai sans heartbeat, l'utilisateur est hors ligne (3 heartbeats manqués). */
    public const ONLINE_WINDOW_SECONDS = 90;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Enregistre un heartbeat ; retourne true si la base a été mise à jour. */
    public function touch(User $user, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $last = $user->getLastActivityAt();

        if ($last && $now->getTimestamp() - $last->getTimestamp() < self::WRITE_THROTTLE_SECONDS) {
            return false;
        }

        $user->setLastActivityAt($now);
        $this->em->flush();

        return true;
    }

    /**
     * À la déconnexion : recule la dernière activité juste avant la fenêtre « en ligne ».
     * La date reste utilisable pour les statistiques (écart < 2 min), mais l'utilisateur
     * disparaît aussitôt des connectés.
     */
    public function markOffline(User $user, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $last = $user->getLastActivityAt();
        $offline = $now->modify(sprintf('-%d seconds', self::ONLINE_WINDOW_SECONDS + 1));

        if ($last && $last > $offline) {
            $user->setLastActivityAt($offline);
            $this->em->flush();
        }
    }

    public function isOnline(User $user, ?\DateTimeImmutable $now = null): bool
    {
        $last = $user->getLastActivityAt();

        return $last !== null && $last >= $this->onlineSince($now);
    }

    public function countOnline(?\DateTimeImmutable $now = null): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.lastActivityAt >= :since')
            ->setParameter('since', $this->onlineSince($now))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Utilisateurs connectés, les plus récemment actifs d'abord.
     *
     * @return User[]
     */
    public function findOnline(int $limit = 20, ?\DateTimeImmutable $now = null): array
    {
        return $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.lastActivityAt >= :since')
            ->setParameter('since', $this->onlineSince($now))
            ->orderBy('u.lastActivityAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function onlineSince(?\DateTimeImmutable $now): \DateTimeImmutable
    {
        return ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d seconds', self::ONLINE_WINDOW_SECONDS));
    }
}
