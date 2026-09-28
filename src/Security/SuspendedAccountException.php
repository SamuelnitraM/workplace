<?php

namespace App\Security;

use App\Entity\User;
use App\Moderation\SuspensionNotice;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/** Login refused because the member is suspended; the message is the reason shown to the member. */
class SuspendedAccountException extends CustomUserMessageAccountStatusException
{
    public function __construct(private readonly User $member)
    {
        parent::__construct(SuspensionNotice::describe($member));
    }

    public function getMember(): User
    {
        return $this->member;
    }
}
