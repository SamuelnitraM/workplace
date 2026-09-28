<?php

namespace App\Controller;

use App\Entity\Appeal;
use App\Entity\User;
use App\Moderation\AppealLink;
use App\Moderation\ModerationService;
use App\Moderation\SuspensionNotice;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Appeal of a suspended member, reached through the signed link of the login page (App\Moderation\AppealLink). */
class AppealController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'appeal';

    public function __construct(
        private readonly AppealLink $appealLink,
        private readonly ModerationService $moderation,
    ) {
    }

    #[Route('/reclamation/{id}', name: 'app_appeal', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function appeal(#[MapEntity(id: 'id')] User $member, Request $request): Response
    {
        if (!$this->appealLink->isValid($request)) {
            $this->addFlash('error', 'Le lien de réclamation a expiré : ouvre à nouveau la page de connexion.');
            return $this->redirectToRoute('app_login');
        }
        if (!$member->isSuspended()) {
            $this->addFlash('info', 'Ton compte n\'est plus suspendu : tu peux te connecter.');
            return $this->redirectToRoute('app_login');
        }
        $existingAppeal = $this->moderation->appealAgainstCurrentSanction($member);
        $message = trim($request->request->getString('message'));
        $error = null;
        if ($existingAppeal === null && $request->isMethod('POST')) {
            $error = match (true) {
                !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) => 'La session a expiré, envoie à nouveau ta réclamation.',
                mb_strlen($message) < Appeal::MESSAGE_MIN_LENGTH => sprintf('Explique ta demande en %d caractères au moins.', Appeal::MESSAGE_MIN_LENGTH),
                mb_strlen($message) > Appeal::MESSAGE_MAX_LENGTH => sprintf('Ta réclamation dépasse %d caractères.', Appeal::MESSAGE_MAX_LENGTH),
                default => null,
            };
            if ($error === null) {
                $this->moderation->submitAppeal($member, $message);
                $this->addFlash('success', 'Ta réclamation a été transmise à la modération. La réponse te sera envoyée par e-mail.');
                return $this->redirectToRoute('app_login');
            }
        }
        return $this->render('appeal/form.html.twig', [
            'member' => $member,
            'notice' => SuspensionNotice::describe($member),
            'existingAppeal' => $existingAppeal,
            'message' => $message,
            'error' => $error,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
            'minLength' => Appeal::MESSAGE_MIN_LENGTH,
            'maxLength' => Appeal::MESSAGE_MAX_LENGTH,
        ]);
    }
}
