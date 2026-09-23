<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Historique d'XP d'un membre (sujet : User) : privé, visible par le membre lui-même uniquement.
 *
 * @extends Voter<string, User>
 */
final class ExperienceHistoryVoter extends Voter
{
    public const VIEW = 'XP_HISTORY_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->getId() !== null && $user->getId() === $subject->getId();
    }
}
