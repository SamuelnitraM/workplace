<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\GamificationService;
use App\Service\LeaderboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Classement général : public (visiteurs compris), critère choisi par ?tri=… (lien partageable). */
final class LeaderboardController extends AbstractController
{
    #[Route('/classement', name: 'app_leaderboard', methods: ['GET'])]
    public function index(Request $request, LeaderboardService $leaderboard, GamificationService $gamification): Response
    {
        $viewer = $this->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;

        $sort = LeaderboardService::normalizeSort($request->query->get('tri'));
        $page = $request->query->get('page');
        $page = is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1; // valeur invalide → page 1
        $board = $leaderboard->getLeaderboard($sort, $page, $viewer);

        return $this->render('leaderboard/index.html.twig', [
            'board' => $board,
            'sorts' => LeaderboardService::SORTS,
            'current' => LeaderboardService::SORTS[$board['sort']],
            'perPage' => LeaderboardService::PER_PAGE,
            'maxRanked' => LeaderboardService::MAX_RANKED,
            'streakStatus' => $viewer !== null && $board['sort'] === 'serie' ? $gamification->getStreakStatus($viewer) : null,
        ]);
    }
}
