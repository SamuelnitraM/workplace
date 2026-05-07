<?php

namespace App\Twig;

use App\Repository\GroupInvitationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private GroupInvitationRepository $groupInvitationRepository,
        private Security $security
    ) {}

    public function getGlobals(): array
    {
        $pendingInvitationsCount = 0;

        $user = $this->security->getUser();
        if ($user) {
            $pendingInvitationsCount = count(
                $this->groupInvitationRepository->findBy([
                    'invitedUser' => $user,
                    'status' => 'pending',
                ])
            );
        }

        return [
            'pendingInvitationsCount' => $pendingInvitationsCount,
        ];
    }
}