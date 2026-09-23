<?php

namespace App\Controller;

use App\Entity\User;
use App\Gamification\ExperienceHistory;
use App\Gamification\UserTitleManager;
use App\Repository\UserRepository;
use App\Security\Voter\ExperienceHistoryVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gamification du profil : titre (badge affiché à côté du pseudo) et historique d'XP (privé).
 */
#[Route('/profil', name: 'app_profil_')]
class ProfilGamificationController extends AbstractController
{
    /**
     * Choisit un badge débloqué comme titre (champ « badge » = code), ou retire le titre (champ vide).
     * Réponse JSON pour un appel AJAX, sinon redirection vers l'onglet Badges du profil.
     */
    #[Route('/settings/title', name: 'title', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function title(Request $request, UserTitleManager $titles): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $wantsJson = $request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json';

        if (!$this->isCsrfTokenValid('profile_title', $request->request->getString('_token'))) {
            if ($wantsJson) {
                return new JsonResponse(['error' => 'Jeton de sécurité invalide, rechargez la page.'], Response::HTTP_FORBIDDEN);
            }
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $code = trim($request->request->getString('badge'));
        $badge = $code !== '' ? $titles->findBadge($code) : null;
        $ok = ($code === '' || $badge !== null) && $titles->setTitle($user, $badge);

        $message = match (true) {
            !$ok => 'Ce badge n’est pas encore débloqué : il ne peut pas servir de titre.',
            $badge === null => 'Titre retiré.',
            default => sprintf('Titre « %s » affiché à côté de votre pseudo.', $badge->getName()),
        };

        if ($wantsJson) {
            return new JsonResponse(
                $ok ? ['ok' => true, 'message' => $message, 'title' => UserTitleManager::payload($user)] : ['error' => $message],
                $ok ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->addFlash($ok ? 'success' : 'error', $message);

        return $this->redirectToRoute('app_profil_show', ['username' => $user->getUsername(), '_fragment' => 'badges']);
    }

    /** Historique d'XP complet, paginé : propriétaire du profil uniquement (403 sinon). */
    #[Route('/{username}/xp-history', name: 'xp_history', methods: ['GET'])]
    public function xpHistory(string $username, Request $request, UserRepository $userRepository, ExperienceHistory $history): Response
    {
        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }
        $this->denyAccessUnlessGranted(ExperienceHistoryVoter::VIEW, $user);

        return $this->render('profil/xp_history.html.twig', [
            'user' => $user,
            'history' => $history->page($user, max(1, $request->query->getInt('page', 1))),
        ]);
    }
}
