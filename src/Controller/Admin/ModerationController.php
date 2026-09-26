<?php

namespace App\Controller\Admin;

use App\Entity\Report;
use App\Entity\User;
use App\Moderation\ContentAction;
use App\Moderation\ModerationDecision;
use App\Moderation\ModerationService;
use App\Moderation\ReportResolution;
use App\Moderation\SuspensionDuration;
use App\Repository\ReportRepository;
use App\Security\Voter\MemberSanctionVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Moderation pages of the back office: decision on a report and sanctions of a member.
 */
#[IsGranted('ROLE_MODERATOR')]
#[AdminRoute('/moderation', name: 'moderation')]
class ModerationController extends AbstractController
{
    private const DECISION_CSRF_PREFIX = 'moderation_report_';
    private const SANCTION_CSRF_PREFIX = 'moderation_member_';

    public function __construct(
        private readonly ModerationService $moderation,
        private readonly ReportRepository $reportRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[AdminRoute('/report/{id}', name: 'report', options: ['methods' => ['GET'], 'requirements' => ['id' => '\d+']])]
    public function report(#[MapEntity(id: 'id')] Report $report): Response
    {
        $targetAvailable = $this->moderation->isTargetAvailable($report);
        return $this->render('admin/moderation/report.html.twig', [
            'report' => $report,
            'targetAvailable' => $targetAvailable,
            'targetHidden' => $targetAvailable && $this->moderation->isTargetHidden($report),
            'relatedReports' => $this->reportRepository->findAllForTarget($report->getTargetType(), $report->getTargetId()),
            'authorHistory' => $report->getTargetAuthor() !== null ? $this->reportRepository->findForTargetAuthor($report->getTargetAuthor()) : [],
            'canSuspendAuthor' => $report->getTargetAuthor() !== null && $this->isGranted(MemberSanctionVoter::SANCTION, $report->getTargetAuthor()),
            'durations' => SuspensionDuration::cases(),
            'contentActions' => array_values(array_filter(ContentAction::cases(), static fn (ContentAction $action): bool => $action->isAllowedFor($report->getTargetType()) && ($action === ContentAction::Keep || $targetAvailable))),
            'csrfTokenId' => self::DECISION_CSRF_PREFIX . $report->getId(),
            'queueUrl' => $this->queueUrl(),
        ]);
    }

    #[AdminRoute('/report/{id}/decision', name: 'report_decision', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function decide(#[MapEntity(id: 'id')] Report $report, Request $request): Response
    {
        $this->denyUnlessDecisionTokenValid($report, $request);
        /** @var User $moderator */
        $moderator = $this->getUser();
        $decision = ModerationDecision::fromForm($request->request);
        $canSanctionAuthor = $report->getTargetAuthor() !== null && $this->isGranted(MemberSanctionVoter::SANCTION, $report->getTargetAuthor());
        $errors = $this->moderation->process($report, $moderator, $decision, $canSanctionAuthor);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }
            return $this->redirectToRoute('admin_moderation_report', ['id' => $report->getId()]);
        }
        $this->addFlash('success', 'Signalement traité : ' . implode(', ', array_map(
            static fn (ReportResolution $resolution): string => mb_strtolower($resolution->label()),
            $decision->resolutions(),
        )) . '.');
        return $this->redirect($this->queueUrl());
    }

    #[AdminRoute('/report/{id}/restore', name: 'report_restore', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function restore(#[MapEntity(id: 'id')] Report $report, Request $request): Response
    {
        $this->denyUnlessDecisionTokenValid($report, $request);
        if (!$this->moderation->isTargetAvailable($report)) {
            $this->addFlash('danger', 'Le contenu n\'existe plus.');
        } else {
            $this->moderation->restoreTarget($report);
            $this->addFlash('success', 'Contenu à nouveau visible.');
        }
        return $this->redirectToRoute('admin_moderation_report', ['id' => $report->getId()]);
    }

    #[AdminRoute('/member/{id}', name: 'member', options: ['methods' => ['GET'], 'requirements' => ['id' => '\d+']])]
    public function member(#[MapEntity(id: 'id')] User $member): Response
    {
        return $this->render('admin/moderation/member.html.twig', [
            'member' => $member,
            'reports' => $this->reportRepository->findBy(['targetAuthor' => $member], ['createdAt' => 'DESC'], 20),
            'canSanction' => $this->isGranted(MemberSanctionVoter::SANCTION, $member),
            'durations' => SuspensionDuration::cases(),
            'csrfTokenId' => self::SANCTION_CSRF_PREFIX . $member->getId(),
        ]);
    }

    #[AdminRoute('/member/{id}/sanction', name: 'member_sanction', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function sanction(#[MapEntity(id: 'id')] User $member, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::SANCTION_CSRF_PREFIX . $member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        if (!$this->isGranted(MemberSanctionVoter::SANCTION, $member)) {
            $this->addFlash('danger', 'Tu ne peux pas sanctionner ce membre (toi-même ou un membre de l\'équipe).');
        } elseif ($request->request->get('decision') === 'lift') {
            $this->moderation->liftSuspension($member);
            $this->addFlash('success', 'Sanction levée : ' . $member->getUsername() . ' peut à nouveau se connecter.');
        } else {
            $duration = SuspensionDuration::tryFrom((string) $request->request->get('duration'));
            $reason = trim((string) $request->request->get('reason'));
            if ($duration === null || $reason === '') {
                $this->addFlash('danger', 'Choisis une durée et indique le motif communiqué au membre.');
            } else {
                $this->moderation->suspend($member, $duration, $reason);
                $this->addFlash('success', $member->getUsername() . ' est suspendu (' . mb_strtolower($duration->label()) . ').');
            }
        }
        return $this->redirectToRoute('admin_moderation_member', ['id' => $member->getId()]);
    }

    private function denyUnlessDecisionTokenValid(Report $report, Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::DECISION_CSRF_PREFIX . $report->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function queueUrl(): string
    {
        return $this->adminUrlGenerator->unsetAll()->setController(ReportCrudController::class)->setAction('index')->generateUrl();
    }
}
