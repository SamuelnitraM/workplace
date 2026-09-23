<?php

namespace App\Security\Voter;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Retrouve l'adhésion (GroupMember) d'un utilisateur à un groupe, avec un cache par requête :
 * les voters (groupe, channel, todo) interrogés plusieurs fois pour le même groupe
 * — par ex. un is_granted() par channel ou par tâche dans un template — ne font qu'une requête SQL.
 */
class GroupMembershipResolver implements ResetInterface
{
    /** @var array<string, GroupMember|null> */
    private array $cache = [];

    public function __construct(private GroupMemberRepository $groupMemberRepository) {}

    public function getMember(?User $user, ?Group $group): ?GroupMember
    {
        if (!$user || !$group || $user->getId() === null || $group->getId() === null) {
            return null;
        }

        $key = $user->getId() . '-' . $group->getId();
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->groupMemberRepository->findOneBy([
                'user' => $user,
                'usergroup' => $group,
            ]);
        }

        return $this->cache[$key];
    }

    /** Vrai si l'utilisateur est membre du groupe avec au moins le rôle donné ('member', 'admin' ou 'owner'). */
    public function hasAtLeastRole(?User $user, ?Group $group, string $role): bool
    {
        return (bool) $this->getMember($user, $group)?->hasAtLeastRole($role);
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
