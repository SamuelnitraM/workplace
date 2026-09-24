<?php

namespace App\Controller;

use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoComment;
use App\Entity\GalleryPhotoLike;
use App\Entity\User;
use App\Event\GalleryPhotoCommentedEvent;
use App\Event\GalleryPhotoLikedEvent;
use App\EventSubscriber\GalleryNotificationSubscriber;
use App\Repository\GalleryPhotoCommentRepository;
use App\Repository\GalleryPhotoLikeRepository;
use App\Repository\GalleryPhotoRepository;
use App\Repository\NotificationRepository;
use App\Security\SubmissionThrottle;
use App\Security\ThrottledAction;
use App\Security\Voter\GalleryPhotoVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Page détail d'une photo de galerie : description, likes et commentaires.
 */
#[Route('/profil/{username}/photo/{id}', name: 'app_gallery_photo_', requirements: ['id' => '\d+'])]
class GalleryController extends AbstractController
{
    public const COMMENTS_PER_PAGE = 20;

    public function __construct(
        private readonly GalleryPhotoRepository $photoRepository,
        private readonly GalleryPhotoCommentRepository $commentRepository,
        private readonly GalleryPhotoLikeRepository $likeRepository,
        private readonly EntityManagerInterface $em,
        private readonly SubmissionThrottle $throttle,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(string $username, int $id, Request $request, NotificationRepository $notificationRepository): Response
    {
        $photo = $this->findViewablePhoto($id);
        if ($photo->getOwner()->getUsername() !== $username) {
            return $this->redirectToRoute('app_gallery_photo_show', ['username' => $photo->getOwner()->getUsername(), 'id' => $id], Response::HTTP_MOVED_PERMANENTLY);
        }

        $commentCount = $this->commentRepository->countForPhoto($photo);
        $pageCount = max(1, (int) ceil($commentCount / self::COMMENTS_PER_PAGE));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));

        $currentUser = $this->getUser();

        // Likes et commentaires de cette photo affichés : leurs notifications sont lues
        if ($currentUser instanceof User) {
            $notificationRepository->markReadByGroupKeyPrefix($currentUser, GalleryNotificationSubscriber::photoKeyPrefix($photo));
        }

        return $this->render('gallery/show.html.twig', [
            'photo' => $photo,
            'owner' => $photo->getOwner(),
            'likeCount' => $this->likeRepository->countForPhoto($photo),
            'likedByMe' => $currentUser instanceof User && $this->likeRepository->findOneByPhotoAndUser($photo, $currentUser) !== null,
            'comments' => $this->commentRepository->findPageForPhoto($photo, $page, self::COMMENTS_PER_PAGE),
            'commentCount' => $commentCount,
            'page' => $page,
            'pageCount' => $pageCount,
            'descriptionMaxLength' => GalleryPhoto::DESCRIPTION_MAX_LENGTH,
            'commentMaxLength' => GalleryPhotoComment::CONTENT_MAX_LENGTH,
        ]);
    }

    /**
     * Like / unlike. Le champ « liked » indique l'état voulu (1 = aimer, 0 = retirer) :
     * un double-clic ne produit donc pas d'aller-retour, et l'opération est idempotente.
     * Répond en JSON pour les requêtes fetch, sinon redirige vers la page de la photo.
     */
    #[Route('/like', name: 'like', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function like(int $id, Request $request, EventDispatcherInterface $dispatcher): Response
    {
        $photo = $this->findViewablePhoto($id);
        $wantsJson = $this->wantsJson($request);

        if (!$this->isCsrfTokenValid('gallery_like_' . $id, (string) $request->request->get('_token'))) {
            return $this->fail($request, $photo, 'Jeton de sécurité invalide, veuillez réessayer.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isGranted(GalleryPhotoVoter::LIKE, $photo)) {
            return $this->fail($request, $photo, 'Vous ne pouvez pas aimer votre propre photo.', Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = $this->getUser();
        $connection = $this->em->getConnection();

        if ($request->request->getBoolean('liked', true)) {
            if ($this->likeRepository->findOneByPhotoAndUser($photo, $user) === null) {
                $created = true;
                try {
                    $this->em->persist(new GalleryPhotoLike($photo, $user));
                    $this->em->flush();
                } catch (UniqueConstraintViolationException) {
                    // Double-clic / requêtes concurrentes : le like existe déjà, rien à faire.
                    // (l'EntityManager est fermé après l'exception : on ne l'utilise plus ci-dessous)
                    $created = false;
                }
                if ($created) {
                    $dispatcher->dispatch(new GalleryPhotoLikedEvent($photo, $user));
                }
            }
        } else {
            $connection->executeStatement('DELETE FROM gallery_photo_like WHERE photo_id = ? AND user_id = ?', [$photo->getId(), $user->getId()]);
        }

        $likeCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM gallery_photo_like WHERE photo_id = ?', [$photo->getId()]);
        $liked = (bool) $connection->fetchOne('SELECT 1 FROM gallery_photo_like WHERE photo_id = ? AND user_id = ?', [$photo->getId(), $user->getId()]);

        if ($wantsJson) {
            return new JsonResponse(['liked' => $liked, 'likeCount' => $likeCount]);
        }

        return $this->redirectToPhoto($photo);
    }

    #[Route('/description', name: 'description', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function editDescription(int $id, Request $request): Response
    {
        $photo = $this->findViewablePhoto($id);
        if (!$this->isGranted(GalleryPhotoVoter::EDIT, $photo) || !$this->isCsrfTokenValid('gallery_description_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $description = (string) $request->request->get('description', '');
        if (mb_strlen(trim($description)) > GalleryPhoto::DESCRIPTION_MAX_LENGTH) {
            $this->addFlash('error', sprintf('La description ne doit pas dépasser %d caractères.', GalleryPhoto::DESCRIPTION_MAX_LENGTH));
            return $this->redirectToPhoto($photo);
        }

        $photo->setDescription($description);
        $this->em->flush();
        $this->addFlash('success', 'Description mise à jour.');

        return $this->redirectToPhoto($photo);
    }

    #[Route('/comment', name: 'comment', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function comment(int $id, Request $request, EventDispatcherInterface $dispatcher): Response
    {
        $photo = $this->findViewablePhoto($id);
        if (!$this->isCsrfTokenValid('gallery_comment_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        if (!$this->isGranted(GalleryPhotoVoter::COMMENT, $photo)) {
            throw $this->createAccessDeniedException();
        }

        $content = trim((string) $request->request->get('content', ''));
        $length = mb_strlen($content);
        if ($length === 0 || $length > GalleryPhotoComment::CONTENT_MAX_LENGTH) {
            $this->addFlash('error', sprintf('Le commentaire doit contenir entre 1 et %d caractères.', GalleryPhotoComment::CONTENT_MAX_LENGTH));
            return $this->redirectToPhoto($photo, anchor: 'comments');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->throttle->tryConsumeForUser(ThrottledAction::PhotoComment, $user)) {
            $this->addFlash('error', ThrottledAction::PhotoComment->refusalMessage());
            return $this->redirectToPhoto($photo, anchor: 'comments');
        }
        $comment = new GalleryPhotoComment($photo, $user, $content);
        $this->em->persist($comment);
        $this->em->flush();
        $dispatcher->dispatch(new GalleryPhotoCommentedEvent($photo, $user, $comment));

        $lastPage = max(1, (int) ceil($this->commentRepository->countForPhoto($photo) / self::COMMENTS_PER_PAGE));

        return $this->redirectToPhoto($photo, $lastPage, 'comment-' . $comment->getId());
    }

    #[Route('/comment/{commentId}/delete', name: 'comment_delete', requirements: ['commentId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteComment(int $id, int $commentId, Request $request): Response
    {
        $photo = $this->findViewablePhoto($id);
        $comment = $this->commentRepository->find($commentId);
        if (!$comment || $comment->getPhoto() !== $photo) {
            throw $this->createNotFoundException('Commentaire introuvable');
        }
        if (!$this->isCsrfTokenValid('gallery_comment_delete_' . $commentId, (string) $request->request->get('_token'))
            || !$this->isGranted(GalleryPhotoVoter::COMMENT_DELETE, $comment)) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($comment);
        $this->em->flush();
        $this->addFlash('success', 'Commentaire supprimé.');

        return $this->redirectToPhoto($photo, max(1, $request->query->getInt('page', 1)), 'comments');
    }

    /** Photo existante et visible par l'utilisateur courant ; sinon 404 (on ne révèle pas l'existence d'une photo masquée). */
    private function findViewablePhoto(int $id): GalleryPhoto
    {
        $photo = $this->photoRepository->find($id);
        if (!$photo || !$this->isGranted(GalleryPhotoVoter::VIEW, $photo)) {
            throw $this->createNotFoundException('Photo introuvable');
        }

        return $photo;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || in_array('application/json', $request->getAcceptableContentTypes(), true);
    }

    private function fail(Request $request, GalleryPhoto $photo, string $message, int $status): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['error' => $message], $status);
        }
        $this->addFlash('error', $message);

        return $this->redirectToPhoto($photo);
    }

    private function redirectToPhoto(GalleryPhoto $photo, int $page = 1, ?string $anchor = null): Response
    {
        $parameters = ['username' => $photo->getOwner()->getUsername(), 'id' => $photo->getId()];
        if ($page > 1) {
            $parameters['page'] = $page;
        }
        if ($anchor !== null) {
            $parameters['_fragment'] = $anchor;
        }

        return $this->redirectToRoute('app_gallery_photo_show', $parameters);
    }
}
