<?php

namespace App\Controller;

use App\Service\GamificationService;
use Doctrine\ORM\EntityManagerInterface;
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
    public function activity(string $key, Request $request, GamificationService $gamification, EntityManagerInterface $entityManager): JsonResponse|RedirectResponse
    {
        if (!in_array($key, ['share', 'legal_bottom'], true)) {
            throw $this->createNotFoundException();
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('gamification_' . $key, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide');
        }
        $gamification->recordActivity($user, $key);
        if ($key === 'legal_bottom') {
            $gamification->recordActivity($user, 'jurist');
        }
        $gamification->syncAllBadges($user);
        $entityManager->flush();

        if (!$request->isXmlHttpRequest() && $request->headers->get('referer')) {
            return $this->redirect($request->headers->get('referer'));
        }

        return new JsonResponse(['ok' => true]);
    }
}
