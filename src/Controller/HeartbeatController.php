<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\PresenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class HeartbeatController extends AbstractController
{
    #[Route('/heartbeat', name: 'app_heartbeat', methods: ['POST'])]
    public function heartbeat(#[CurrentUser] ?User $user, PresenceService $presence): Response
    {
        if ($user) {
            $presence->touch($user);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
