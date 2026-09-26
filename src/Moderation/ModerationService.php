<?php

namespace App\Moderation;

use App\Entity\GalleryPhoto;
use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\Report;
use App\Entity\Thread;
use App\Entity\User;
use App\Mailer\TransactionalMailer;
use App\Repository\PostRepository;
use App\Repository\ReportRepository;
use App\Service\GalleryPhotoUploader;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Every moderation decision goes through this service: report submission, combined decision on a report
 * (fate of the content, warning, suspension, dismissal) and sanctions of a member. A decision closes all
 * pending reports of the same target and the author is told what happened (site notification and e-mail).
 */
class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReportRepository $reportRepository,
        private readonly ReportTargetResolver $targetResolver,
        private readonly PostRepository $postRepository,
        private readonly GalleryPhotoUploader $galleryPhotoUploader,
        private readonly NotificationService $notificationService,
        private readonly TransactionalMailer $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function submitReport(User $reporter, ReportTargetType $type, object $target, ReportReason $reason, ?string $details): Report
    {
        $report = new Report(
            $reporter,
            $type,
            (int) $target->getId(),
            $this->targetResolver->authorOf($type, $target),
            $reason,
            $details,
            $this->targetResolver->excerptOf($type, $target),
            $this->targetResolver->urlOf($type, $target),
        );
        $this->em->persist($report);
        $this->em->flush();
        return $report;
    }

    /** @return list<string> reasons why the decision cannot be applied, empty when it can */
    private function validate(Report $report, ModerationDecision $decision, bool $canSanctionAuthor): array
    {
        $type = $report->getTargetType();
        $targetAvailable = $this->isTargetAvailable($report);
        return array_values(array_filter([
            !$report->isPending() ? 'Ce signalement a déjà été traité.' : null,
            $decision->contentAction !== ContentAction::Keep && !$targetAvailable ? 'Le contenu n\'existe plus.' : null,
            !$decision->contentAction->isAllowedFor($type) ? sprintf('Action impossible sur ce type de contenu : %s.', mb_strtolower($decision->contentAction->label())) : null,
            $decision->concernsAuthor() && $report->getTargetAuthor() === null ? 'L\'auteur n\'existe plus.' : null,
            $decision->suspension !== null && $decision->suspensionReason === null ? 'Indique le motif de la suspension communiqué au membre.' : null,
            $decision->suspension !== null && $report->getTargetAuthor() !== null && !$canSanctionAuthor ? 'Tu ne peux pas suspendre ce membre de l\'équipe.' : null,
        ]));
    }

    /**
     * Validates then applies every action of the decision, closes the pending reports of the target with all the
     * resolutions, then sends the author one notice gathering the content decision and the warning (the suspension
     * has its own e-mail). Nothing is applied when the decision is not valid.
     *
     * @return list<string> reasons why the decision was refused, empty when it was applied
     */
    public function process(Report $report, User $moderator, ModerationDecision $decision, bool $canSanctionAuthor): array
    {
        $errors = $this->validate($report, $decision, $canSanctionAuthor);
        if ($errors !== []) {
            return $errors;
        }
        match ($decision->contentAction) {
            ContentAction::Keep => null,
            ContentAction::Hide => $this->hideTarget($report),
            ContentAction::Delete => $this->deleteTarget($report),
        };
        $this->closeReportsOfTarget($report, $decision->resolutions(), $moderator, $decision->summary());
        $author = $report->getTargetAuthor();
        $notice = $this->authorNotice($report, $decision);
        if ($author !== null && $notice !== null) {
            $this->notifyAuthor($author, $notice);
        }
        if ($author !== null && $decision->suspension !== null) {
            $this->suspend($author, $decision->suspension, (string) $decision->suspensionReason);
        }
        return [];
    }

    /** Shows again a content hidden by moderation. The reports stay closed. */
    public function restoreTarget(Report $report): void
    {
        $target = $this->requireTarget($report);
        match ($report->getTargetType()) {
            ReportTargetType::Post, ReportTargetType::Photo => $target->setHiddenByModeration(false),
            ReportTargetType::Thread => $this->postRepository->findFirstPostOfThread($target)?->setHiddenByModeration(false),
            default => null,
        };
        $this->em->flush();
    }

    /** The member is logged out at the next request (App\EventSubscriber\SuspendedUserSubscriber). */
    public function suspend(User $member, SuspensionDuration $duration, string $reason): void
    {
        $until = $duration->endsAt(new \DateTimeImmutable());
        $member->suspend($until, $reason);
        $this->em->flush();
        $this->mailer->send($member, 'Ton compte SprueHub est suspendu', 'email/account_suspended.html.twig', [
            'notice' => SuspensionNotice::describe($member),
        ]);
    }

    public function liftSuspension(User $member): void
    {
        $member->liftSuspension();
        $this->em->flush();
    }

    public function isTargetAvailable(Report $report): bool
    {
        return $this->targetResolver->find($report->getTargetType(), $report->getTargetId()) !== null;
    }

    public function isTargetHidden(Report $report): bool
    {
        $target = $this->targetResolver->find($report->getTargetType(), $report->getTargetId());
        return match (true) {
            $target instanceof Post, $target instanceof GalleryPhoto => $target->isHiddenByModeration(),
            $target instanceof Thread => $this->postRepository->findFirstPostOfThread($target)?->isHiddenByModeration() ?? false,
            default => false,
        };
    }

    /** Members see a notice instead of the content, moderators still see it. */
    private function hideTarget(Report $report): void
    {
        $target = $this->requireTarget($report);
        match ($report->getTargetType()) {
            ReportTargetType::Post, ReportTargetType::Photo => $target->setHiddenByModeration(true),
            ReportTargetType::Thread => $this->hideThread($target),
            default => throw new \LogicException(sprintf('A %s cannot be hidden.', $report->getTargetType()->value)),
        };
    }

    private function deleteTarget(Report $report): void
    {
        $target = $this->requireTarget($report);
        match ($report->getTargetType()) {
            ReportTargetType::Thread => $this->deleteThread($target),
            ReportTargetType::Post => $target->isFirst() ? $this->deleteThread($target->getThread()) : $this->deletePost($target),
            ReportTargetType::Photo => $this->galleryPhotoUploader->delete($target),
            ReportTargetType::PhotoComment, ReportTargetType::PrivateMessage, ReportTargetType::GroupMessage, ReportTargetType::Group => $this->em->remove($target),
            ReportTargetType::Profile => throw new \LogicException('A profile cannot be deleted from a report.'),
        };
    }

    /** Message sent to the author about the content and the warning, NULL when neither concerns them. */
    private function authorNotice(Report $report, ModerationDecision $decision): ?string
    {
        $contentVerb = match ($decision->contentAction) {
            ContentAction::Hide => 'masqué',
            ContentAction::Delete => 'supprimé',
            ContentAction::Keep => null,
        };
        $sentences = array_filter([
            $contentVerb !== null ? sprintf(
                'Ton contenu (%s) a été %s par la modération. Motif du signalement : %s.',
                mb_strtolower($report->getTargetType()->label()),
                $contentVerb,
                mb_strtolower($report->getReason()->label()),
            ) : null,
            $decision->warning !== null ? 'Avertissement de la modération : ' . $decision->warning : null,
        ]);
        return $sentences === [] ? null : implode(' ', $sentences);
    }

    private function requireTarget(Report $report): object
    {
        return $this->targetResolver->find($report->getTargetType(), $report->getTargetId())
            ?? throw new \LogicException('The reported content no longer exists.');
    }

    /** The opening post is hidden and the thread locked, so the discussion cannot go on around it. */
    private function hideThread(Thread $thread): void
    {
        $this->postRepository->findFirstPostOfThread($thread)?->setHiddenByModeration(true);
        $thread->setIsLocked(true);
    }

    private function deleteThread(Thread $thread): void
    {
        // The solution link is cleared first: thread and posts reference each other
        $thread->setSolutionPost(null);
        $this->em->flush();
        $this->em->remove($thread);
    }

    private function deletePost(Post $post): void
    {
        $thread = $post->getThread();
        if ($thread->getSolutionPost() === $post) {
            $thread->setSolutionPost(null);
        }
        $this->em->remove($post);
    }

    /**
     * Closes the report and every other pending report of the same target, then flushes.
     *
     * @param list<ReportResolution> $resolutions
     */
    private function closeReportsOfTarget(Report $report, array $resolutions, User $moderator, ?string $note): void
    {
        $reports = $this->reportRepository->findPendingForTarget($report->getTargetType(), $report->getTargetId());
        if (!in_array($report, $reports, true)) {
            $reports[] = $report;
        }
        foreach ($reports as $pendingReport) {
            $pendingReport->close($resolutions, $moderator, $note);
        }
        $this->em->flush();
    }

    private function notifyAuthor(User $author, string $message): void
    {
        $this->notificationService->notify(
            $author,
            Notification::TYPE_MODERATION_NOTICE,
            null,
            ['message' => $message],
            $this->urlGenerator->generate('app_terms'),
        );
        $this->mailer->send($author, 'Message de la modération SprueHub', 'email/moderation_notice.html.twig', [
            'message' => $message,
        ]);
    }
}
