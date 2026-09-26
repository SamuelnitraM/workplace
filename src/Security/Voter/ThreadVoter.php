<?php

namespace App\Security\Voter;

use App\Entity\Thread;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Règles d'accès aux sujets du forum :
 *  - REPLY     : connecté, sujet ouvert, catégorie non en lecture seule (sauf administrateur) ;
 *  - SOLVE     : choisir / retirer la réponse « solution » — auteur du sujet ou administrateur ;
 *  - SUBSCRIBE : suivre / ne plus suivre le sujet — tout membre connecté.
 *
 * @extends Voter<string, Thread>
 */
final class ThreadVoter extends Voter
{
    public const REPLY = 'THREAD_REPLY';
    public const SOLVE = 'THREAD_SOLVE';
    public const SUBSCRIBE = 'THREAD_SUBSCRIBE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Thread && in_array($attribute, [self::REPLY, self::SOLVE, self::SUBSCRIBE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Thread $subject */
        return match ($attribute) {
            self::REPLY => !$subject->isLocked() && ($subject->getCategory() === null || CategoryVoter::canWriteIn($subject->getCategory(), $this->security)),
            self::SOLVE => $subject->getAuthor()?->getId() === $user->getId() || $this->security->isGranted('ROLE_ADMIN'),
            self::SUBSCRIBE => true,
            default => false,
        };
    }
}
