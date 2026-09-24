<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ReportFormType;
use App\Moderation\ModerationService;
use App\Moderation\ReportTargetResolver;
use App\Moderation\ReportTargetType;
use App\Repository\ReportRepository;
use App\Security\SubmissionThrottle;
use App\Security\ThrottledAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** "Signaler" button of every content: report form sent to the moderation queue. */
#[IsGranted('ROLE_USER')]
class ReportController extends AbstractController
{
    #[Route('/signaler/{type}/{id}', name: 'app_report', requirements: ['type' => new EnumRequirement(ReportTargetType::class), 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function report(
        ReportTargetType $type,
        int $id,
        Request $request,
        ReportTargetResolver $targetResolver,
        ReportRepository $reportRepository,
        ModerationService $moderation,
        SubmissionThrottle $throttle,
    ): Response {
        /** @var User $reporter */
        $reporter = $this->getUser();
        $target = $targetResolver->find($type, $id);
        if ($target === null || !$targetResolver->canBeReportedBy($type, $target, $reporter)) {
            throw $this->createNotFoundException('Contenu introuvable.');
        }
        $backUrl = $targetResolver->urlOf($type, $target) ?? $this->conversationUrl($target, $reporter);
        if ($reportRepository->hasPendingReportFrom($reporter, $type, $id)) {
            $this->addFlash('info', 'Tu as déjà signalé ce contenu : l\'équipe de modération va l\'examiner.');
            return $this->redirect($backUrl);
        }
        $form = $this->createForm(ReportFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $throttle->acceptsMemberForm($form, ThrottledAction::Report, $reporter)) {
            $moderation->submitReport($reporter, $type, $target, $form->get('reason')->getData(), $form->get('details')->getData());
            $this->addFlash('success', 'Merci, ton signalement a été transmis à l\'équipe de modération.');
            return $this->redirect($backUrl);
        }
        return $this->render('report/new.html.twig', [
            'form' => $form,
            'targetDescription' => $targetResolver->describe($type, $target),
            'backUrl' => $backUrl,
        ]);
    }

    /** A private message has no public page: back to the conversation with its other participant. */
    private function conversationUrl(object $privateMessage, User $reporter): string
    {
        $otherParticipant = $privateMessage->getConversation()->getOtherParticipant($reporter);
        return $this->generateUrl('app_message_show', ['username' => $otherParticipant?->getUsername()]);
    }
}
