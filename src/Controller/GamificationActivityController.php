<?php

namespace App\Controller;

use App\Service\GamificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/gamification')]
class GamificationActivityController extends AbstractController
{
    #[Route('/activity/{key}', name: 'app_gamification_activity', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function activity(string $key, Request $request, GamificationService $gamification): JsonResponse|RedirectResponse
    {
        // Juriste = simple visite des pages légales (GamificationSubscriber) : seule l'activité « share » reste ici
        if ($key !== 'share') {
            throw $this->createNotFoundException();
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('gamification_' . $key, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide');
        }
        // Idempotent, y compris en cas de double clic concurrent
        $gamification->recordActivity($user, $key);

        if (!$request->isXmlHttpRequest()) {
            // Ne rediriger vers le Referer que s'il pointe vers ce site (évite une redirection ouverte)
            $referer = (string) $request->headers->get('referer', '');
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $refererScheme = parse_url($referer, PHP_URL_SCHEME);
            if ($referer !== '' && $refererHost === $request->getHost() && in_array($refererScheme, ['http', 'https'], true)) {
                return $this->redirect($referer);
            }

            return $this->redirectToRoute('app_home');
        }

        return new JsonResponse(['ok' => true]);
    }
}
