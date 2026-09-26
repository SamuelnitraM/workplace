<?php

namespace App\Group;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Groups page of a member (templates/group/index.html.twig):
 *  - the member's groups, ordered by most recent activity or by the member's own order (User::groupSortMode);
 *  - group suggestions: public groups joined by the member's friends (the most friends first), then the most
 *    populated public groups open to join;
 *  - saving of the member's own order (drag and drop).
 */
final class GroupDirectory
{
    public const SUGGESTIONS = 6;
    /** Friends shown (avatars) on a suggestion. */
    public const SUGGESTION_FRIENDS = 3;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<array{group: Group, membership: GroupMember, lastActivity: \DateTimeImmutable}>
     */
    public function groupsOf(User $member): array
    {
        /** @var GroupMember[] $memberships */
        $memberships = $this->entityManager->createQueryBuilder()
            ->select('membership', 'grp', 'creator')
            ->from(GroupMember::class, 'membership')
            ->innerJoin('membership.usergroup', 'grp')
            ->innerJoin('grp.creator', 'creator')
            ->where('membership.user = :member')
            ->setParameter('member', $member)
            ->getQuery()
            ->getResult();
        $activities = $this->lastActivities(array_map(static fn (GroupMember $membership): int => $membership->getUsergroup()->getId(), $memberships));
        $entries = array_map(static fn (GroupMember $membership): array => [
            'group' => $membership->getUsergroup(),
            'membership' => $membership,
            'lastActivity' => $activities[$membership->getUsergroup()->getId()] ?? $membership->getUsergroup()->getCreatedAt(),
        ], $memberships);
        usort($entries, $member->getGroupSortMode() === User::GROUP_SORT_CUSTOM
            ? static fn (array $left, array $right): int => [$left['membership']->getPosition() ?? PHP_INT_MAX, $right['lastActivity']] <=> [$right['membership']->getPosition() ?? PHP_INT_MAX, $left['lastActivity']]
            : static fn (array $left, array $right): int => $right['lastActivity'] <=> $left['lastActivity']);
        return $entries;
    }

    /**
     * Saves the member's own order of their groups; groups missing from the list keep their place after the others.
     *
     * @param int[] $orderedGroupIds
     */
    public function saveOrder(User $member, array $orderedGroupIds): void
    {
        $positions = array_flip(array_values(array_unique(array_map('intval', $orderedGroupIds))));
        foreach ($member->getGroupMembers() as $membership) {
            $groupId = $membership->getUsergroup()->getId();
            $membership->setPosition($positions[$groupId] ?? count($positions) + $groupId);
        }
        $member->setGroupSortMode(User::GROUP_SORT_CUSTOM);
        $this->entityManager->flush();
    }

    /**
     * @return list<array{group: Group, friends: User[], friendCount: int, memberCount: int}>
     */
    public function suggestionsFor(User $member): array
    {
        $connection = $this->entityManager->getConnection();
        $friendIds = array_map('intval', $connection->fetchFirstColumn(
            "SELECT CASE WHEN requester_id = :member THEN receiver_id ELSE requester_id END FROM friendship
             WHERE status = 'accepted' AND (requester_id = :member OR receiver_id = :member)",
            ['member' => $member->getId()]
        ));
        $excluded = 'grp.id NOT IN (SELECT own.usergroup_id FROM group_member own WHERE own.user_id = :member)';
        $rows = $friendIds === [] ? [] : $connection->fetchAllAssociative(
            "SELECT grp.id, COUNT(DISTINCT friend.user_id) AS friends
             FROM `group` grp INNER JOIN group_member friend ON friend.usergroup_id = grp.id AND friend.user_id IN (:friends)
             WHERE grp.is_public = 1 AND $excluded
             GROUP BY grp.id ORDER BY friends DESC, grp.id DESC LIMIT " . self::SUGGESTIONS,
            ['friends' => $friendIds, 'member' => $member->getId()],
            ['friends' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $friendCounts = array_column($rows, 'friends', 'id');
        if (count($friendCounts) < self::SUGGESTIONS) {
            $popular = $connection->fetchFirstColumn(
                "SELECT grp.id FROM `group` grp LEFT JOIN group_member everyone ON everyone.usergroup_id = grp.id
                 WHERE grp.is_public = 1 AND grp.is_joinable = 1 AND $excluded
                 GROUP BY grp.id ORDER BY COUNT(everyone.id) DESC, grp.id DESC LIMIT " . (self::SUGGESTIONS * 2),
                ['member' => $member->getId()]
            );
            foreach ($popular as $groupId) {
                if (count($friendCounts) >= self::SUGGESTIONS) {
                    break;
                }
                $friendCounts[(int) $groupId] ??= 0;
            }
        }
        if ($friendCounts === []) {
            return [];
        }
        $groups = [];
        foreach ($this->entityManager->getRepository(Group::class)->findBy(['id' => array_keys($friendCounts)]) as $group) {
            $groups[$group->getId()] = $group;
        }
        $suggestions = [];
        foreach ($friendCounts as $groupId => $friendCount) {
            $group = $groups[(int) $groupId] ?? null;
            if ($group === null) {
                continue;
            }
            $friends = [];
            foreach ($group->getMembers() as $groupMember) {
                if (in_array($groupMember->getUser()->getId(), $friendIds, true) && count($friends) < self::SUGGESTION_FRIENDS) {
                    $friends[] = $groupMember->getUser();
                }
            }
            $suggestions[] = ['group' => $group, 'friends' => $friends, 'friendCount' => (int) $friendCount, 'memberCount' => $group->getMembers()->count()];
        }
        return $suggestions;
    }

    /**
     * Date of the latest message of each group.
     *
     * @param int[] $groupIds
     * @return array<int, \DateTimeImmutable>
     */
    private function lastActivities(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT usergroup_id, MAX(created_at) FROM group_message WHERE usergroup_id IN (:groups) GROUP BY usergroup_id',
            ['groups' => $groupIds],
            ['groups' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        return array_map(static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date), $rows);
    }
}
