<?php

namespace App\Security\Voter;

use App\Entity\Category;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Forum category access rules:
 *  - CREATE_THREAD: signed in, thread creation enabled, and administrator when the category is read-only.
 *
 * @extends Voter<string, Category>
 */
final class CategoryVoter extends Voter
{
    public const CREATE_THREAD = 'CATEGORY_CREATE_THREAD';

    public function __construct(private readonly Security $security)
    {
    }

    /** Whether the member may write in the category (read-only categories are reserved to administrators). */
    public static function canWriteIn(Category $category, Security $security): bool
    {
        return !$category->isReadOnly() || $security->isGranted('ROLE_ADMIN');
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::CREATE_THREAD && $subject instanceof Category;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token->getUser() instanceof User) {
            return false;
        }
        /** @var Category $subject */
        return $subject->isAllowThreads() && self::canWriteIn($subject, $this->security);
    }
}
