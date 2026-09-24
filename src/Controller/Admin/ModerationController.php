<?php

namespace App\Controller\Admin;

use App\Entity\Report;
use App\Entity\User;
use App\Moderation\ModerationService;
use App\Moderation\SuspensionDuration;
use App\Repository\ReportRepository;
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
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute('/moderation', name: 'moderation')]
class ModerationController extends AbstractController
{
    private const DECISION_CSRF_PREFIX = 'moderation_report_';
    private const SANCTION_CSRF_PREFIX = 'moderation_member_';
    private const DECISION_CONFIRMATIONS = [
        'hide' => 'Contenu masqué.',
        'restore' => 'Contenu à nouveau visible.',
        'delete' => 'Contenu supprimé.',
        'warn' => 'Avertissement envoyé à l\'auteur.',
        'suspend' => 'Auteur suspendu.',
        'dismiss' => 'Signalement classé sans suite.',
    ];

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
            'durations' => SuspensionDuration::cases(),
            'csrfTokenId' => self::DECISION_CSRF_PREFIX . $report->getId(),
            'queueUrl' => $this->queueUrl(),
        ]);
    }

    #[AdminRoute('/report/{id}/decision', name: 'report_decision', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function decide(#[MapEntity(id: 'id')] Report $report, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::DECISION_CSRF_PREFIX . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        /** @var User $moderator */
        $moderator = $this->getUser();
        $note = trim((string) $request->request->get('note'));
        $decision = (string) $request->request->get('decision');
        $targetAvailable = $this->moderation->isTargetAvailable($report);
        $error = match (true) {
            !array_key_exists($decision, self::DECISION_CONFIRMATIONS) => 'Décision inconnue.',
            $decision === 'restore' => $targetAvailable ? null : 'Le contenu n\'existe plus.',
            !$report->isPending() => 'Ce signalement a déjà été traité.',
            in_array($decision, ['hide', 'delete'], true) && !$targetAvailable => 'Le contenu n\'existe plus.',
            $decision === 'hide' && !$report->getTargetType()->canBeHidden() => 'Ce type de contenu ne peut pas être masqué.',
            $decision === 'delete' && !$report->getTargetType()->canBeDeleted() => 'Ce type de contenu ne peut pas être supprimé.',
            in_array($decision, ['warn', 'suspend'], true) && $report->getTargetAuthor() === null => 'L\'auteur n\'existe plus.',
            in_array($decision, ['warn', 'suspend'], true) && $note === '' => 'Indique le message ou le motif transmis au membre.',
            $decision === 'suspend' && SuspensionDuration::tryFrom((string) $request->request->get('duration')) === null => 'Choisis une durée de suspension.',
            default => null,
        };
        if ($error !== null) {
            $this->addFlash('danger', $error);
            return $this->redirectToRoute('admin_moderation_report', ['id' => $report->getId()]);
        }
        match ($decision) {
            'hide' => $this->moderation->hideTarget($report, $moderator, $note),
            'restore' => $this->moderation->restoreTarget($report),
            'delete' => $this->moderation->deleteTarget($report, $moderator, $note),
            'warn' => $this->moderation->warnAuthor($report, $moderator, $note),
            'suspend' => $this->moderation->suspendAuthor($report, $moderator, SuspensionDuration::from((string) $request->request->get('duration')), $note),
            'dismiss' => $this->moderation->dismiss($report, $moderator, $note),
        };
        $this->addFlash('success', self::DECISION_CONFIRMATIONS[$decision]);
        return $decision === 'restore'
            ? $this->redirectToRoute('admin_moderation_report', ['id' => $report->getId()])
            : $this->redirect($this->queueUrl());
    }

    #[AdminRoute('/member/{id}', name: 'member', options: ['methods' => ['GET'], 'requirements' => ['id' => '\d+']])]
    public function member(#[MapEntity(id: 'id')] User $member): Response
    {
        return $this->render('admin/moderation/member.html.twig', [
            'member' => $member,
            'reports' => $this->reportRepository->findBy(['targetAuthor' => $member], ['createdAt' => 'DESC'], 20),
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
        if ($member === $this->getUser()) {
            $this->addFlash('danger', 'Impossible de te sanctionner toi-même.');
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

    private function queueUrl(): string
    {
        return $this->adminUrlGenerator->unsetAll()->setController(ReportCrudController::class)->setAction('index')->generateUrl();
    }
}
