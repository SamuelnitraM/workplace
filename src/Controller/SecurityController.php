<?php

namespace App\Controller;

use App\Moderation\SuspensionNotice;
use App\Moderation\SuspensionNoticeCookie;
use App\Security\SuspendedAccountException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    /**
     * Login page. A suspended member recognised by the suspension notice cookie sees the reason of the
     * suspension at every visit while it lasts; a cookie naming nobody suspended anymore is removed.
     */
    #[Route(path: '/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, SuspensionNoticeCookie $noticeCookie): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $suspendedMember = $noticeCookie->memberFrom($request);
        $response = $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $suspendedMember !== null && $error instanceof SuspendedAccountException ? null : $error,
            'suspendedMember' => $suspendedMember,
            'suspensionNotice' => $suspendedMember !== null ? SuspensionNotice::describe($suspendedMember) : null,
        ]);
        if ($suspendedMember === null && $noticeCookie->isPresent($request)) {
            $response->headers->setCookie($noticeCookie->clear());
        }
        return $response;
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
