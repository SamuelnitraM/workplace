<?php

namespace App\Moderation;

use App\Controller\ThreadController;
use App\Entity\GalleryPhoto;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\PostRepository;
use App\Security\Voter\GalleryPhotoVoter;
use App\Security\Voter\GroupChannelVoter;
use App\Security\Voter\GroupVoter;
use App\Service\NotificationRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Maps a report target (type + id) to its entity and describes it: author, excerpt, link,
 * and whether the current member may report it.
 */
class ReportTargetResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $postRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    public function find(ReportTargetType $type, int $id): ?object
    {
        return $this->em->find($type->entityClass(), $id);
    }

    public function authorOf(ReportTargetType $type, object $target): ?User
    {
        return match ($type) {
            ReportTargetType::Thread, ReportTargetType::Post, ReportTargetType::PhotoComment, ReportTargetType::PrivateMessage, ReportTargetType::GroupMessage => $target->getAuthor(),
            ReportTargetType::Photo => $target->getOwner(),
            ReportTargetType::Profile => $target,
            ReportTargetType::Group => $this->ownerOf($target),
        };
    }

    /** Plain-text summary of the target, stored with the report. */
    public function excerptOf(ReportTargetType $type, object $target): string
    {
        $text = match ($type) {
            ReportTargetType::Thread => $target->getTitle() . ' — ' . ($this->postRepository->findFirstPostOfThread($target)?->getContent() ?? ''),
            ReportTargetType::Post, ReportTargetType::PhotoComment, ReportTargetType::PrivateMessage => (string) $target->getContent(),
            ReportTargetType::GroupMessage => sprintf('#%s (%s) — %s', $target->getChannel()?->getName(), $target->getUsergroup()?->getName(), $target->getContent()),
            ReportTargetType::Group => $target->getName() . ($target->getDescription() ? ' — ' . $target->getDescription() : ''),
            ReportTargetType::Photo => 'Photo ' . $target->getFilename() . ($target->getDescription() ? ' — ' . $target->getDescription() : ''),
            ReportTargetType::Profile => $target->getUsername() . ($target->getBio() ? ' — ' . $target->getBio() : ''),
        };
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > 480 ? rtrim(mb_substr($text, 0, 479)) . '…' : $text;
    }

    /** Link to the target on the site (NULL for a private message: only its participants can open it). */
    public function urlOf(ReportTargetType $type, object $target): ?string
    {
        return match ($type) {
            ReportTargetType::Thread => $this->urlGenerator->generate('app_thread_show', ['slug' => $target->getSlug()]),
            ReportTargetType::Post => $this->urlGenerator->generate('app_thread_show', [
                'slug' => $target->getThread()->getSlug(),
                'page' => $this->postRepository->findPageOfPost($target, ThreadController::POSTS_PER_PAGE),
                '_fragment' => 'post-' . $target->getId(),
            ]),
            ReportTargetType::Photo => $this->photoUrl($target),
            ReportTargetType::PhotoComment => $this->photoUrl($target->getPhoto()) . '#comment-' . $target->getId(),
            ReportTargetType::PrivateMessage => null,
            ReportTargetType::Profile => $this->urlGenerator->generate('app_profil_show', ['username' => $target->getUsername()]),
            ReportTargetType::Group => $this->urlGenerator->generate('app_group_show', ['slug' => $target->getSlug()]),
            ReportTargetType::GroupMessage => $this->urlGenerator->generate('app_group_show', [
                'slug' => $target->getUsergroup()->getSlug(),
                'channel' => $target->getChannel()?->getId(),
                '_fragment' => 'msg-' . $target->getId(),
            ]),
        };
    }

    /** A member reports what they can see, never their own content or profile. */
    public function canBeReportedBy(ReportTargetType $type, object $target, User $reporter): bool
    {
        if ($this->authorOf($type, $target) === $reporter) {
            return false;
        }
        return match ($type) {
            ReportTargetType::Photo => $this->security->isGranted(GalleryPhotoVoter::VIEW, $target),
            ReportTargetType::PhotoComment => $this->security->isGranted(GalleryPhotoVoter::VIEW, $target->getPhoto()),
            ReportTargetType::PrivateMessage => $target->getConversation()?->hasParticipant($reporter) ?? false,
            ReportTargetType::Group => $this->security->isGranted(GroupVoter::VIEW, $target),
            ReportTargetType::GroupMessage => $target->getChannel() !== null && $this->security->isGranted(GroupChannelVoter::READ, $target->getChannel()),
            default => true,
        };
    }

    /** Short description for the report form ("Réponse de X : …"). */
    public function describe(ReportTargetType $type, object $target): string
    {
        $author = $this->authorOf($type, $target)?->getUsername() ?? 'un membre';
        return $type === ReportTargetType::Profile
            ? 'Profil de ' . $author
            : sprintf('%s de %s : « %s »', $type->label(), $author, NotificationRenderer::excerpt($this->excerptOf($type, $target)));
    }

    /** Owner of a group (member with the owner role), who answers for the group. */
    private function ownerOf(Group $group): ?User
    {
        foreach ($group->getMembers() as $member) {
            if ($member->getRole() === 'owner') {
                return $member->getUser();
            }
        }
        return $group->getCreator();
    }

    private function photoUrl(GalleryPhoto $photo): string
    {
        return $this->urlGenerator->generate('app_gallery_photo_show', [
            'username' => $photo->getOwner()->getUsername(),
            'id' => $photo->getId(),
        ]);
    }
}
