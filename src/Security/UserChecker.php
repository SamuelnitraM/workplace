<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses the login (form or remember-me cookie) of a suspended member, with the reason.
 * The « Membre supprimé » account is refused like wrong credentials: it has no reason to show.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isDeletedMemberAccount()) {
            throw new BadCredentialsException();
        }
        if ($user instanceof User && $user->isSuspended()) {
            throw new SuspendedAccountException($user);
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
