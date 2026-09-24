<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserBlock;
use App\Repository\FriendshipRepository;
use App\Repository\UserBlockRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Blocking between members. Blocking ends any friendship or pending request between the two members,
 * which also closes their private conversation (private messages are reserved to friends).
 */
class MemberBlocker
{
    public function __construct(
        private readonly UserBlockRepository $blockRepository,
        private readonly FriendshipRepository $friendshipRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function block(User $blocker, User $blocked): void
    {
        if ($blocker === $blocked) {
            return;
        }
        $friendship = $this->friendshipRepository->findExisting($blocker, $blocked);
        if ($friendship !== null) {
            $this->em->remove($friendship);
        }
        if ($this->blockRepository->findOneByPair($blocker, $blocked) === null) {
            $this->em->persist(new UserBlock($blocker, $blocked));
        }
        $this->em->flush();
    }

    public function unblock(User $blocker, User $blocked): void
    {
        $block = $this->blockRepository->findOneByPair($blocker, $blocked);
        if ($block === null) {
            return;
        }
        $this->em->remove($block);
        $this->em->flush();
    }

    public function isBlockedEitherWay(User $firstUser, User $secondUser): bool
    {
        return $this->blockRepository->isBlockedEitherWay($firstUser, $secondUser);
    }

    public function hasBlocked(User $blocker, User $blocked): bool
    {
        return $this->blockRepository->findOneByPair($blocker, $blocked) !== null;
    }
}
