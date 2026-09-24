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
 * Every moderation decision goes through this service: report submission, hiding, deletion,
 * warning, suspension and dismissal. A decision closes all pending reports of the same target
 * and the author is told what happened (site notification and e-mail).
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

    /** Hides the target: members see a notice instead of the content, moderators still see it. */
    public function hideTarget(Report $report, User $moderator, ?string $note): void
    {
        $target = $this->requireTarget($report);
        match ($report->getTargetType()) {
            ReportTargetType::Post => $target->setHiddenByModeration(true),
            ReportTargetType::Thread => $this->hideThread($target),
            ReportTargetType::Photo => $target->setHiddenByModeration(true),
            default => throw new \LogicException(sprintf('A %s cannot be hidden.', $report->getTargetType()->value)),
        };
        $this->closeReportsOfTarget($report, ReportResolution::Hidden, $moderator, $note);
        $this->notifyAuthor($report->getTargetAuthor(), sprintf(
            'Ton contenu (%s) a été masqué par la modération. Motif du signalement : %s.',
            mb_strtolower($report->getTargetType()->label()),
            mb_strtolower($report->getReason()->label()),
        ));
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

    public function deleteTarget(Report $report, User $moderator, ?string $note): void
    {
        $target = $this->requireTarget($report);
        match ($report->getTargetType()) {
            ReportTargetType::Thread => $this->deleteThread($target),
            ReportTargetType::Post => $target->isFirst() ? $this->deleteThread($target->getThread()) : $this->deletePost($target),
            ReportTargetType::Photo => $this->galleryPhotoUploader->delete($target),
            ReportTargetType::PhotoComment, ReportTargetType::PrivateMessage => $this->em->remove($target),
            ReportTargetType::Profile => throw new \LogicException('A profile cannot be deleted from a report.'),
        };
        $this->closeReportsOfTarget($report, ReportResolution::Deleted, $moderator, $note);
        $this->notifyAuthor($report->getTargetAuthor(), sprintf(
            'Ton contenu (%s) a été supprimé par la modération. Motif du signalement : %s.',
            mb_strtolower($report->getTargetType()->label()),
            mb_strtolower($report->getReason()->label()),
        ));
    }

    public function warnAuthor(Report $report, User $moderator, string $message): void
    {
        $this->closeReportsOfTarget($report, ReportResolution::Warned, $moderator, $message);
        $this->notifyAuthor($report->getTargetAuthor(), 'Avertissement de la modération : ' . $message);
    }

    public function suspendAuthor(Report $report, User $moderator, SuspensionDuration $duration, string $reason): void
    {
        $author = $report->getTargetAuthor() ?? throw new \LogicException('The reported content has no author anymore.');
        $this->closeReportsOfTarget($report, ReportResolution::Suspended, $moderator, $reason);
        $this->suspend($author, $duration, $reason);
    }

    public function dismiss(Report $report, User $moderator, ?string $note): void
    {
        $this->closeReportsOfTarget($report, ReportResolution::Dismissed, $moderator, $note);
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

    /** Closes the report and every other pending report of the same target, then flushes. */
    private function closeReportsOfTarget(Report $report, ReportResolution $resolution, User $moderator, ?string $note): void
    {
        $reports = $this->reportRepository->findPendingForTarget($report->getTargetType(), $report->getTargetId());
        if (!in_array($report, $reports, true)) {
            $reports[] = $report;
        }
        foreach ($reports as $pendingReport) {
            $pendingReport->close($resolution, $moderator, $note);
        }
        $this->em->flush();
    }

    private function notifyAuthor(?User $author, string $message): void
    {
        if ($author === null) {
            return;
        }
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
