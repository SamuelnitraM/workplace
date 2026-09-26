<?php

namespace App\Security\Voter;

use App\Entity\TodoNode;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un noeud de todo (liste > catégorie > tâche).
 *
 * - Noeud personnel (usergroup null) : son propriétaire a tous les droits (hors assignations, propres aux groupes).
 * - Noeud de groupe :
 *   - « rédacteur » (membre dont le rôle >= Group::todoWriteRole, cf. GroupVoter::TODO_WRITE) : crée, renomme,
 *     supprime, fait progresser toute tâche et voit tout ;
 *   - « lecteur » (rôle >= Group::todoViewRole, cf. GroupVoter::TODO_VIEW_ALL) : voit toute la todo ;
 *   - membre assigné à une tâche (assignation acceptée) : la voit et la fait progresser ;
 *     une demande en attente lui laisse voir la tâche ;
 *   - assignations (tâches seulement, App\Todo\TodoAssignmentManager) :
 *     - ASSIGN : gérer les assignés d'une tâche (assigner un membre, accepter ou refuser une demande, retirer un assigné) :
 *       rôle >= Group::getAssignmentManagerRole() (propriétaire, ou administrateurs et propriétaire) ;
 *     - REQUEST_ASSIGNMENT : demander à être assigné (ou s'assigner directement en mode libre) : membre qui voit la tâche
 *       et n'y est pas encore assigné ni en attente ;
 *     - WITHDRAW_REQUEST : annuler sa propre demande en attente.
 * - PROGRESS ne concerne que les tâches ; CREATE_CHILD que les listes (→ catégorie) et catégories (→ tâche).
 *
 * Aucune requête SQL ici hormis l'adhésion (mise en cache par GroupMembershipResolver) :
 * les assignations sont lues sur les noeuds déjà chargés (cf. TodoNodeRepository::findGroupLists).
 *
 * @extends Voter<string, TodoNode>
 */
final class TodoNodeVoter extends Voter
{
    public const VIEW = 'TODO_VIEW';
    /** Renommer. */
    public const EDIT = 'TODO_EDIT';
    public const DELETE = 'TODO_DELETE';
    /** Faire avancer / reculer / valider la progression d'une tâche. */
    public const PROGRESS = 'TODO_PROGRESS';
    /** Gérer les assignés d'une tâche. */
    public const ASSIGN = 'TODO_ASSIGN';
    /** Demander à être assigné à une tâche. */
    public const REQUEST_ASSIGNMENT = 'TODO_REQUEST_ASSIGNMENT';
    /** Annuler sa demande d'assignation en attente. */
    public const WITHDRAW_REQUEST = 'TODO_WITHDRAW_REQUEST';
    /** Ajouter un enfant à ce noeud (catégorie dans une liste, tâche dans une catégorie). */
    public const CREATE_CHILD = 'TODO_CREATE_CHILD';

    private const ATTRIBUTES = [self::VIEW, self::EDIT, self::DELETE, self::PROGRESS, self::ASSIGN, self::REQUEST_ASSIGNMENT, self::WITHDRAW_REQUEST, self::CREATE_CHILD];
    private const ASSIGNMENT_ATTRIBUTES = [self::ASSIGN, self::REQUEST_ASSIGNMENT, self::WITHDRAW_REQUEST];

    public function __construct(private GroupMembershipResolver $membership) {}

    public function supportsAttribute(string $attribute): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true);
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, TodoNode::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof TodoNode && $this->supportsAttribute($attribute);
    }

    /** @param TodoNode $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $type = $subject->getType();
        if (in_array($attribute, [self::PROGRESS, ...self::ASSIGNMENT_ATTRIBUTES], true) && $type !== TodoNode::TYPE_ITEM) {
            return false;
        }
        if ($attribute === self::CREATE_CHILD && $type === TodoNode::TYPE_ITEM) {
            return false;
        }
        $group = $subject->getUsergroup();
        // Todo personnelle
        if ($group === null) {
            return !in_array($attribute, self::ASSIGNMENT_ATTRIBUTES, true) && $subject->getOwner() === $user;
        }
        // Todo de groupe
        $member = $this->membership->getMember($user, $group);
        if (!$member) {
            return false;
        }
        $isWriter = $member->hasAtLeastRole($group->getTodoWriteRole());
        $ownAssignment = $type === TodoNode::TYPE_ITEM ? $subject->assignmentOf($user) : null;
        return match ($attribute) {
            self::VIEW => $isWriter || $member->hasAtLeastRole($group->getTodoViewRole()) || $this->concernsMember($subject, $user),
            self::EDIT, self::DELETE, self::CREATE_CHILD => $isWriter,
            self::PROGRESS => $isWriter || $subject->isAssignedTo($user),
            self::ASSIGN => $member->hasAtLeastRole($group->getAssignmentManagerRole()),
            self::REQUEST_ASSIGNMENT => $ownAssignment === null
                && ($isWriter || $member->hasAtLeastRole($group->getTodoViewRole())),
            self::WITHDRAW_REQUEST => $ownAssignment !== null && $ownAssignment->isPending(),
            default => false,
        };
    }

    // Membre ni rédacteur ni lecteur : voit les tâches où il est assigné (ou en attente) et le contexte qui les contient
    private function concernsMember(TodoNode $node, User $user): bool
    {
        if ($node->getType() === TodoNode::TYPE_ITEM) {
            return $node->assignmentOf($user) !== null;
        }
        foreach ($node->getChildren() as $child) {
            if ($this->concernsMember($child, $user)) {
                return true;
            }
        }
        return false;
    }
}
