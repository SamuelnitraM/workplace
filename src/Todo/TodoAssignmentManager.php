<?php

namespace App\Todo;

use App\Entity\Notification;
use App\Entity\TodoAssignment;
use App\Entity\TodoNode;
use App\Entity\User;
use App\Security\Voter\GroupMembershipResolver;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Single entry point of the task assignments of a group to-do list (rights are checked beforehand by TodoNodeVoter):
 *  - request(): a member takes a task; accepted directly in free mode (Group::isFreeAssignment()) or for a manager
 *    of the assignments (isManager()), pending otherwise, and the managers are then notified;
 *  - assign(): a manager assigns a member (a pending request of this member is accepted);
 *  - accept() / refuse(): decision on a pending request, the member is notified;
 *  - remove(): a manager removes an assignee, a member withdraws their pending request.
 * A task accepts at most Group::getMaxAssigneesPerTask() accepted assignees; pending requests do not count.
 * Each method flushes and returns null on success, or an error message in French.
 */
final class TodoAssignmentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupMembershipResolver $membership,
        private readonly NotificationService $notifications,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function request(TodoNode $task, User $member): ?string
    {
        if ($error = $this->checkCandidate($task, $member)) {
            return $error;
        }
        $group = $task->getUsergroup();
        if ($group->isFreeAssignment() || $this->isManager($task, $member)) {
            if ($error = $this->checkCapacity($task)) {
                return $error;
            }
            $task->getAssignments()->add(new TodoAssignment($task, $member, TodoAssignment::STATUS_ACCEPTED));
            $this->entityManager->flush();
            return null;
        }
        $task->getAssignments()->add(new TodoAssignment($task, $member, TodoAssignment::STATUS_PENDING));
        $this->entityManager->flush();
        $managers = [];
        foreach ($group->getMembers() as $groupMember) {
            if ($groupMember->hasAtLeastRole($group->getAssignmentManagerRole())) {
                $managers[] = $groupMember->getUser();
            }
        }
        $this->notifications->notifyMany($managers, Notification::TYPE_TODO_ASSIGNMENT, $member, $this->data($task, 'requested'), $this->taskUrl($task), 'todo_request:' . $group->getId());
        return null;
    }

    public function assign(TodoNode $task, User $member, User $manager): ?string
    {
        $existing = $task->assignmentOf($member);
        if ($existing !== null) {
            return $existing->isPending() ? $this->accept($existing, $manager) : sprintf('%s est déjà assigné à cette tâche.', $member->getUsername());
        }
        if ($error = $this->checkCandidate($task, $member) ?? $this->checkCapacity($task)) {
            return $error;
        }
        $task->getAssignments()->add(new TodoAssignment($task, $member, TodoAssignment::STATUS_ACCEPTED));
        $this->entityManager->flush();
        $this->notifications->notify($member, Notification::TYPE_TODO_ASSIGNMENT, $manager, $this->data($task, 'assigned'), $this->taskUrl($task));
        return null;
    }

    public function accept(TodoAssignment $assignment, User $manager): ?string
    {
        if (!$assignment->isPending()) {
            return null;
        }
        if ($error = $this->checkCapacity($assignment->getNode())) {
            return $error;
        }
        $assignment->accept();
        $this->entityManager->flush();
        $this->notifications->notify($assignment->getUser(), Notification::TYPE_TODO_ASSIGNMENT, $manager, $this->data($assignment->getNode(), 'accepted'), $this->taskUrl($assignment->getNode()));
        return null;
    }

    public function refuse(TodoAssignment $assignment, User $manager): void
    {
        $task = $assignment->getNode();
        $member = $assignment->getUser();
        $task->getAssignments()->removeElement($assignment);
        $this->entityManager->flush();
        $this->notifications->notify($member, Notification::TYPE_TODO_ASSIGNMENT, $manager, $this->data($task, 'refused'), $this->taskUrl($task));
    }

    public function remove(TodoAssignment $assignment): void
    {
        $assignment->getNode()->getAssignments()->removeElement($assignment);
        $this->entityManager->flush();
    }

    /** Member allowed to approve requests, assign other members and remove assignees of the task's group. */
    public function isManager(TodoNode $task, User $member): bool
    {
        return (bool) $this->membership->getMember($member, $task->getUsergroup())?->hasAtLeastRole($task->getUsergroup()->getAssignmentManagerRole());
    }

    private function checkCandidate(TodoNode $task, User $member): ?string
    {
        if ($task->getType() !== TodoNode::TYPE_ITEM || $task->getUsergroup() === null) {
            return 'Seules les tâches d\'un groupe peuvent être assignées.';
        }
        if ($this->membership->getMember($member, $task->getUsergroup()) === null) {
            return sprintf('%s n\'est pas membre du groupe.', $member->getUsername());
        }
        if ($task->assignmentOf($member) !== null) {
            return sprintf('%s est déjà assigné ou en attente pour cette tâche.', $member->getUsername());
        }
        return null;
    }

    private function checkCapacity(TodoNode $task): ?string
    {
        $limit = $task->getUsergroup()->getMaxAssigneesPerTask();
        if (count($task->getAcceptedAssignments()) >= $limit) {
            return sprintf('Cette tâche a déjà %d membre%s assigné%s, le maximum réglé pour ce groupe.', $limit, $limit > 1 ? 's' : '', $limit > 1 ? 's' : '');
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function data(TodoNode $task, string $outcome): array
    {
        return ['task' => $task->getTitle(), 'taskId' => $task->getId(), 'group' => $task->getUsergroup()->getName(), 'groupId' => $task->getUsergroup()->getId(), 'outcome' => $outcome];
    }

    private function taskUrl(TodoNode $task): string
    {
        return $this->urlGenerator->generate('app_group_todo_index', ['slug' => $task->getUsergroup()->getSlug(), '_fragment' => 'item-' . $task->getId()]);
    }
}
