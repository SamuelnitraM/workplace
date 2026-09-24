<?php

namespace App\Service;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Form\UserProfileFormType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Présentation guidée (/bienvenue, OnboardingController) et liste « Premiers pas » de l'accueil.
 *
 * Étapes : 1 faction, 2 profil (avatar, bio, première photo), 3 groupes, 4 récapitulatif.
 * User::onboardingStep mémorise l'étape à reprendre ; User::onboardingCompletedAt = NULL tant que
 * la présentation n'est pas terminée (bannière sur l'accueil, jamais de redirection forcée).
 */
final class OnboardingService
{
    public const STEP_COUNT = 4;

    /** Titres des étapes (indicateur de progression). */
    public const STEPS = [
        1 => 'Ta faction principale',
        2 => 'Ton profil',
        3 => 'Rejoins des groupes',
        4 => 'Prêt pour la bataille !',
    ];

    public const MAX_GROUP_SUGGESTIONS = 6;

    public function __construct(private EntityManagerInterface $em) {}

    /** Valeurs de faction acceptées (mêmes choix que le formulaire de profil). */
    public static function factionValues(): array
    {
        return array_merge(...array_map('array_values', array_values(UserProfileFormType::factionChoices())));
    }

    /** Passe à l'étape suivante (sans jamais revenir en arrière dans l'étape mémorisée). Pas de flush. */
    public function advanceTo(User $user, int $step): void
    {
        $step = max(1, min(self::STEP_COUNT, $step));
        $user->setOnboardingStep(max($user->getOnboardingStep(), $step));
    }

    /** Relance la présentation : étape 1, bannière réaffichée ; les données déjà saisies sont conservées. Pas de flush. */
    public function restart(User $user): void
    {
        $user->setOnboardingCompletedAt(null)->setOnboardingStep(1);
    }

    public function complete(User $user): void
    {
        $user->setOnboardingCompletedAt(new \DateTimeImmutable())->setOnboardingStep(self::STEP_COUNT);
    }

    /**
     * Groupes proposés à l'étape 3 (une seule requête) : groupes publics ouverts dont l'utilisateur n'est pas membre,
     * les plus pertinents d'abord (nom/description contenant la faction choisie), puis les plus peuplés.
     * Les groupes rejoints pendant la présentation ($joinedIds) restent affichés (« ✓ Rejoint »).
     *
     * @param int[] $joinedIds
     * @return list<array{group: Group, memberCount: int, joined: bool}>
     */
    public function suggestGroups(User $user, array $joinedIds = []): array
    {
        $faction = $user->getFavoriteFaction();
        $relevance = $faction
            ? 'CASE WHEN (g.name LIKE :keyword OR g.description LIKE :keyword) THEN 1 ELSE 0 END'
            : '0';

        $qb = $this->em->createQueryBuilder()
            ->select('g AS grp', 'COUNT(m.id) AS memberCount', 'SUM(CASE WHEN m.user = :user THEN 1 ELSE 0 END) AS isMember', $relevance . ' AS HIDDEN relevance')
            ->from(Group::class, 'g')
            ->leftJoin('g.members', 'm')
            ->where('g.isPublic = true')
            ->andWhere(sprintf(
                '(g.isJoinable = true AND NOT EXISTS (SELECT gm.id FROM %s gm WHERE gm.usergroup = g AND gm.user = :user)) OR g.id IN (:joined)',
                GroupMember::class,
            ))
            ->setParameter('user', $user)
            ->setParameter('joined', $joinedIds ?: [0])
            ->groupBy('g.id')
            ->orderBy('relevance', 'DESC')
            ->addOrderBy('memberCount', 'DESC')
            ->addOrderBy('g.createdAt', 'DESC')
            ->setMaxResults(self::MAX_GROUP_SUGGESTIONS);
        if ($faction) {
            $qb->setParameter('keyword', '%' . addcslashes($faction, '%_\\') . '%');
        }

        $suggestions = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $joined = (int) $row['isMember'] > 0;
            // Groupe rejoint puis quitté entre-temps et fermé : plus rien à proposer
            if (!$joined && !$row['grp']->isJoinable()) {
                continue;
            }
            $suggestions[] = ['group' => $row['grp'], 'memberCount' => (int) $row['memberCount'], 'joined' => $joined];
        }

        return $suggestions;
    }

    /**
     * Liste « Premiers pas » de l'accueil : champs déjà chargés de l'utilisateur + UNE requête (EXISTS).
     *
     * @return array{items: list<array{key: string, label: string, done: bool, route: string, params: array}>, done: int, total: int, complete: bool}
     */
    public function checklist(User $user): array
    {
        $flags = $this->em->getConnection()->fetchAssociative(
            'SELECT
                EXISTS(SELECT 1 FROM gallery_photo WHERE owner_id = :u) AS photo,
                EXISTS(SELECT 1 FROM group_member WHERE user_id = :u) AS grp,
                EXISTS(SELECT 1 FROM thread WHERE author_id = :u) AS thread,
                EXISTS(SELECT 1 FROM friendship WHERE (requester_id = :u OR receiver_id = :u) AND status = \'accepted\') AS friend',
            ['u' => $user->getId()],
        ) ?: [];

        $profile = ['username' => $user->getUsername()];
        $items = [
            ['key' => 'avatar', 'label' => 'Ajouter une photo de profil', 'done' => (bool) $user->getAvatar(), 'route' => 'app_profil_edit', 'params' => []],
            ['key' => 'faction', 'label' => 'Choisir ta faction', 'done' => (bool) $user->getFavoriteFaction(), 'route' => 'app_onboarding_step', 'params' => ['step' => 1]],
            ['key' => 'bio', 'label' => 'Renseigner ta bio', 'done' => trim((string) $user->getBio()) !== '', 'route' => 'app_profil_edit', 'params' => []],
            ['key' => 'photo', 'label' => 'Publier une première photo dans ta galerie', 'done' => (bool) ($flags['photo'] ?? false), 'route' => 'app_profil_show', 'params' => $profile],
            ['key' => 'group', 'label' => 'Rejoindre un groupe', 'done' => (bool) ($flags['grp'] ?? false), 'route' => 'app_group_index', 'params' => []],
            ['key' => 'thread', 'label' => 'Publier ton premier sujet', 'done' => (bool) ($flags['thread'] ?? false), 'route' => 'app_forum_index', 'params' => []],
            ['key' => 'friend', 'label' => 'Ajouter un ami', 'done' => (bool) ($flags['friend'] ?? false), 'route' => 'app_leaderboard', 'params' => []],
        ];
        $done = count(array_filter($items, static fn (array $item) => $item['done']));

        return ['items' => $items, 'done' => $done, 'total' => count($items), 'complete' => $done === count($items)];
    }
}
