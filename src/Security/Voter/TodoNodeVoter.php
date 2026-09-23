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
 * - Noeud personnel (usergroup null) : son propriétaire a tous les droits.
 * - Noeud de groupe :
 *   - « rédacteur » (membre dont le rôle >= Group::todoWriteRole, cf. GroupVoter::TODO_WRITE) : tous les droits ;
 *   - membre assigné à une CATÉGORIE : la voit avec toutes ses tâches, fait progresser chacune d'elles
 *     et peut y créer des tâches (ni renommage, ni suppression, ni assignation) ;
 *   - membre assigné à une TÂCHE : la voit (dans sa catégorie/liste) et la fait progresser ;
 *   - « lecteur » (rôle >= Group::todoViewRole, cf. GroupVoter::TODO_VIEW_ALL) : voit toute la todo
 *     en lecture seule, en plus des droits liés à ses éventuelles assignations ;
 *   - autres membres : ne voient rien d'autre de la todo.
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
    /** Changer l'utilisateur assigné. */
    public const ASSIGN = 'TODO_ASSIGN';
    /** Ajouter un enfant à ce noeud (catégorie dans une liste, tâche dans une catégorie). */
    public const CREATE_CHILD = 'TODO_CREATE_CHILD';

    private const ATTRIBUTES = [self::VIEW, self::EDIT, self::DELETE, self::PROGRESS, self::ASSIGN, self::CREATE_CHILD];

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
        if ($attribute === self::PROGRESS && $type !== TodoNode::TYPE_ITEM) {
            return false;
        }
        if ($attribute === self::CREATE_CHILD && $type === TodoNode::TYPE_ITEM) {
            return false;
        }

        $group = $subject->getUsergroup();

        // Todo personnelle
        if ($group === null) {
            return $subject->getOwner() === $user;
        }

        // Todo de groupe
        $member = $this->membership->getMember($user, $group);
        if (!$member) {
            return false;
        }
        if ($member->hasAtLeastRole($group->getTodoWriteRole())) {
            return true;
        }

        return match ($attribute) {
            // Lecteur (rôle >= Group::todoViewRole) : voit tout, sans autre droit que ceux de ses assignations
            self::VIEW => $member->hasAtLeastRole($group->getTodoViewRole()) || $this->canView($subject, $user),
            self::PROGRESS => $this->isAssignedToItemOrCategory($subject, $user),
            self::CREATE_CHILD => $type === TodoNode::TYPE_CATEGORY && $subject->getAssignedTo() === $user,
            default => false, // EDIT, DELETE, ASSIGN : rédacteurs uniquement
        };
    }

    // Non-rédacteur : voit ce qui lui est assigné et le contexte (catégorie/liste) qui le contient
    private function canView(TodoNode $node, User $user): bool
    {
        return match ($node->getType()) {
            TodoNode::TYPE_ITEM => $this->isAssignedToItemOrCategory($node, $user),
            TodoNode::TYPE_CATEGORY => $node->getAssignedTo() === $user || $this->hasAssignedChild($node, $user),
            TodoNode::TYPE_LIST => $this->hasVisibleChild($node, $user),
            default => false,
        };
    }

    // Tâche assignée à l'utilisateur, ou dont la catégorie lui est assignée
    private function isAssignedToItemOrCategory(TodoNode $item, User $user): bool
    {
        if ($item->getAssignedTo() === $user) {
            return true;
        }
        $parent = $item->getParent();

        return $parent !== null && $parent->getType() === TodoNode::TYPE_CATEGORY && $parent->getAssignedTo() === $user;
    }

    private function hasAssignedChild(TodoNode $node, User $user): bool
    {
        foreach ($node->getChildren() as $child) {
            if ($child->getAssignedTo() === $user) {
                return true;
            }
        }

        return false;
    }

    // Liste : au moins une catégorie assignée, ou une tâche assignée (sous une catégorie ou directement sous la liste)
    private function hasVisibleChild(TodoNode $list, User $user): bool
    {
        foreach ($list->getChildren() as $child) {
            if ($child->getAssignedTo() === $user
                || ($child->getType() === TodoNode::TYPE_CATEGORY && $this->hasAssignedChild($child, $user))) {
                return true;
            }
        }

        return false;
    }
}
