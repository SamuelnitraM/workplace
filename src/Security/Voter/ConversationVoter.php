<?php

namespace App\Security\Voter;

use App\Entity\PrivateConversation;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Accès à une conversation privée : réservé à ses deux participants.
 *
 * @extends Voter<string, PrivateConversation>
 */
final class ConversationVoter extends Voter
{
    public const VIEW = 'CONVERSATION_VIEW';

    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === self::VIEW;
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, PrivateConversation::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof PrivateConversation && $this->supportsAttribute($attribute);
    }

    /** @param PrivateConversation $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->hasParticipant($user);
    }
}
