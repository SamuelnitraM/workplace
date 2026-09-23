<?php

namespace App\EventSubscriber;

use App\Controller\GalleryController;
use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoComment;
use App\Entity\Notification;
use App\Entity\User;
use App\Event\GalleryPhotoCommentedEvent;
use App\Event\GalleryPhotoLikedEvent;
use App\Repository\GalleryPhotoCommentRepository;
use App\Service\NotificationRenderer;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Notifications de la galerie :
 *  - like : propriétaire de la photo, agrégé par photo (« X et 3 autres personnes ont aimé votre photo ») ;
 *  - commentaire : propriétaire de la photo et commentateurs précédents (dédoublonnés, limités), agrégé par photo.
 */
final class GalleryNotificationSubscriber implements EventSubscriberInterface
{
    private const MAX_NOTIFIED_COMMENTERS = 20;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly GalleryPhotoCommentRepository $commentRepository,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            GalleryPhotoLikedEvent::class => 'onPhotoLiked',
            GalleryPhotoCommentedEvent::class => 'onPhotoCommented',
        ];
    }

    /** Préfixe des clés d'agrégation d'une photo (utilisé pour marquer comme lu à l'ouverture de la photo). */
    public static function photoKeyPrefix(GalleryPhoto $photo): string
    {
        return 'photo:' . $photo->getId() . ':';
    }

    public function onPhotoLiked(GalleryPhotoLikedEvent $event): void
    {
        $photo = $event->photo;
        $owner = $photo->getOwner();
        if (!$owner) {
            return;
        }

        $this->notifications->notify(
            $owner,
            Notification::TYPE_PHOTO_LIKE,
            $event->actor,
            ['photoId' => $photo->getId()],
            $this->photoUrl($photo),
            self::photoKeyPrefix($photo) . 'like',
        );
    }

    public function onPhotoCommented(GalleryPhotoCommentedEvent $event): void
    {
        $photo = $event->photo;
        $owner = $photo->getOwner();
        if (!$owner) {
            return;
        }

        $url = $this->commentUrl($photo, $event->comment);
        $key = self::photoKeyPrefix($photo) . 'comment';
        $data = [
            'photoId' => $photo->getId(),
            'excerpt' => NotificationRenderer::excerpt($event->comment->getContent()),
        ];

        $this->notifications->notify($owner, Notification::TYPE_PHOTO_COMMENT, $event->actor, $data + ['own' => true], $url, $key);

        // Une photo masquée n'est visible que par son propriétaire : pas de notification aux autres
        if (!$photo->isVisible()) {
            return;
        }

        $commenterIds = $this->commentRepository->createQueryBuilder('c')
            ->select('IDENTITY(c.author) AS authorId', 'MAX(c.createdAt) AS HIDDEN lastComment')
            ->where('c.photo = :photo')
            ->andWhere('c.author NOT IN (:excluded)')
            ->groupBy('c.author')
            ->orderBy('lastComment', 'DESC')
            ->setMaxResults(self::MAX_NOTIFIED_COMMENTERS)
            ->setParameter('photo', $photo)
            ->setParameter('excluded', [$owner->getId(), $event->actor->getId()])
            ->getQuery()
            ->getSingleColumnResult();

        if ($commenterIds) {
            $commenters = $this->em->getRepository(User::class)->findBy(['id' => $commenterIds]);
            $this->notifications->notifyMany(
                $commenters,
                Notification::TYPE_PHOTO_COMMENT,
                $event->actor,
                $data + ['own' => false, 'owner' => $owner->getUsername(), 'byOwner' => $event->actor->getId() === $owner->getId()],
                $url,
                $key,
            );
        }
    }

    private function photoUrl(GalleryPhoto $photo, array $extra = []): string
    {
        return $this->urlGenerator->generate('app_gallery_photo_show', [
            'username' => $photo->getOwner()->getUsername(),
            'id' => $photo->getId(),
        ] + $extra);
    }

    /** Lien vers la dernière page des commentaires, ancré sur le commentaire. */
    private function commentUrl(GalleryPhoto $photo, GalleryPhotoComment $comment): string
    {
        $page = max(1, (int) ceil($this->commentRepository->countForPhoto($photo) / GalleryController::COMMENTS_PER_PAGE));
        $extra = ['_fragment' => 'comment-' . $comment->getId()];
        if ($page > 1) {
            $extra['page'] = $page;
        }

        return $this->photoUrl($photo, $extra);
    }
}
