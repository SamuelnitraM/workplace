<?php

namespace App\Gamification;

use App\Entity\Badge;
use App\Entity\User;
use App\Entity\UserBadge;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Titre de profil : UN badge débloqué, affiché à côté du pseudo (templates/gamification/_user_title.html.twig).
 * Toute écriture passe par ici (ou par le formulaire de profil, restreint à unlockedBadgesQueryBuilder()) :
 * le badge doit être débloqué par le membre (ligne user_badge).
 */
class UserTitleManager
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** Badges débloqués par le membre, du plus prestigieux au plus modeste (liste du formulaire de profil). */
    public static function unlockedBadgesQueryBuilder(EntityRepository $badgeRepository, ?User $user): QueryBuilder
    {
        return $badgeRepository->createQueryBuilder('b')
            ->innerJoin(UserBadge::class, 'ub', 'WITH', 'ub.badge = b AND ub.user = :titleOwner')
            ->setParameter('titleOwner', $user?->getId() ?? 0)
            ->orderBy('b.xpReward', 'DESC')
            ->addOrderBy('b.name', 'ASC');
    }

    public function isUnlocked(User $user, Badge $badge): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM user_badge WHERE user_id = ? AND badge_id = ?',
            [$user->getId(), $badge->getId()],
        );
    }

    /** Badge (catalogue actuel) par code, ou null. */
    public function findBadge(string $code): ?Badge
    {
        if ($code === '' || BadgeCatalog::get($code) === null) {
            return null;
        }

        return $this->entityManager->getRepository(Badge::class)->findOneBy(['code' => $code]);
    }

    /**
     * Choisit (ou retire, $badge = null) le titre du membre. Retourne false si le badge n'est pas débloqué.
     */
    public function setTitle(User $user, ?Badge $badge): bool
    {
        if ($badge !== null && !$this->isUnlocked($user, $badge)) {
            return false;
        }

        $user->setTitleBadge($badge);
        $this->entityManager->flush();

        return true;
    }

    /** Données du titre pour le client (temps réel du chat) : null si aucun titre. */
    public static function payload(?User $user): ?array
    {
        $badge = $user?->getTitleBadge();
        if ($badge === null) {
            return null;
        }

        return ['name' => $badge->getName(), 'tier' => $badge->getTier(), 'icon' => $badge->getIcon()];
    }
}
