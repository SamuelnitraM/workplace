<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\PostVote;
use App\Entity\Thread;
use App\Entity\User;
use App\Form\ThreadFormType;
use App\Form\PostFormType;
use App\Repository\CategoryRepository;
use App\Repository\NotificationRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use App\Service\GamificationService;
use App\Service\NotificationService;

#[Route('/forum', name: 'app_thread_')]
class ThreadController extends AbstractController
{
    private const POSTS_PER_PAGE = 10;
    /** Nombre maximal de participants précédents notifiés d'une nouvelle réponse. */
    private const MAX_NOTIFIED_PARTICIPANTS = 20;

    #[Route('/thread/{slug}/post/{postId}/vote/{type}', name: 'vote', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function vote(
        string $slug,
        int $postId,
        string $type,
        Request $request,
        PostRepository $postRepository,
        EntityManagerInterface $em,
        GamificationService $gamification
    ): Response {
        if (!in_array($type, [PostVote::TYPE_POSITIVE, PostVote::TYPE_HELPFUL], true)) {
            throw $this->createNotFoundException('Type de vote introuvable');
        }

        if (!$this->isCsrfTokenValid('post_vote_' . $postId . '_' . $type, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide');
        }

        $post = $postRepository->find($postId);
        if (!$post || $post->getThread()?->getSlug() !== $slug) {
            throw $this->createNotFoundException('Réponse introuvable');
        }

        $page = max(1, $request->request->getInt('page', 1));
        $redirectParams = ['slug' => $slug, 'page' => $page, '_fragment' => 'post-' . $post->getId()];

        if ($post->getThread()->isLocked()) {
            $this->addFlash('warning', 'Ce sujet est fermé, il n\'est plus possible de voter.');

            return $this->redirectToRoute('app_thread_show', $redirectParams);
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($post->getAuthor()?->getId() === $user->getId()) {
            $this->addFlash('warning', 'Vous ne pouvez pas voter pour votre propre réponse.');

            return $this->redirectToRoute('app_thread_show', $redirectParams);
        }

        $existingVote = null;
        foreach ($post->getVotes() as $vote) {
            if ($vote->getUser()?->getId() === $user->getId() && $vote->getType() === $type) {
                $existingVote = $vote;
                break;
            }
        }

        if ($existingVote) {
            // Retrait du vote : les badges déjà obtenus par l'auteur ne sont pas retirés
            $em->remove($existingVote);
            $em->flush();
        } else {
            $vote = (new PostVote())
                ->setPost($post)
                ->setUser($user)
                ->setType($type);
            $em->persist($vote);
            $em->flush();
            // Populaire / Dévoué de l'auteur de la réponse (votes d'autres membres uniquement)
            $gamification->onVoteReceived($post, $type);
        }

        return $this->redirectToRoute('app_thread_show', $redirectParams);
    }

    #[Route('/thread/{slug}', name: 'show')]
    public function show(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        ThreadRepository $threadRepository,
        PostRepository $postRepository,
        PaginatorInterface $paginator,
        GamificationService $gamification,
        NotificationService $notifications,
        NotificationRepository $notificationRepository,
    ): Response {
        $thread = $threadRepository->findOneBy(['slug' => $slug]);

        if (!$thread) {
            throw $this->createNotFoundException('Sujet introuvable');
        }

        // Les réponses à ce sujet sont affichées : leurs notifications sont lues
        if ($this->getUser() instanceof User) {
            $notificationRepository->markReadByGroupKey($this->getUser(), self::threadNotificationKey($thread));
        }

        // Archéologue : ouverture d'un sujet de plus d'un an
        if ($this->getUser() instanceof User) {
            $gamification->onThreadViewed($this->getUser(), $thread);
        }

        $sessionKey = 'viewed_thread_' . $thread->getId();
        if (!$request->getSession()->has($sessionKey)) {
            $thread->setViews($thread->getViews() + 1);
            $request->getSession()->set($sessionKey, true);
            $em->flush();
        }

        $query = $postRepository->createQueryBuilder('p')
            ->addSelect('a', 'tb', 'v')
            ->innerJoin('p.author', 'a')
            ->leftJoin('a.titleBadge', 'tb') // titre des auteurs (pas de N+1)
            ->leftJoin('p.votes', 'v')
            ->where('p.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('p.createdAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery();

        // distinct => true : pagination correcte malgré la jointure de la collection des votes.
        // Le tri par paramètre d'URL est désactivé (évite une 500 sur ?sort= invalide).
        $posts = $paginator->paginate(
            $query,
            max(1, $request->query->getInt('page', 1)),
            self::POSTS_PER_PAGE,
            ['distinct' => true, PaginatorInterface::SORT_FIELD_PARAMETER_NAME => null]
        );

        $form = null;
        if ($this->getUser() && !$thread->isLocked()) {
            $post = new Post();
            $form = $this->createForm(PostFormType::class, $post);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                /** @var \App\Entity\User $user */
                $user = $this->getUser();
                $post->setAuthor($user);
                $post->setThread($thread);
                $post->setIsFirst(false);
                $thread->setUpdatedAt(new \DateTimeImmutable());

                $em->persist($post);
                $em->flush();
                // Maître forgeron de l'auteur du sujet (réponse d'un autre membre)
                $gamification->onPostCreated($post);

                $this->addFlash('success', 'Réponse ajoutée avec succès !');
                $lastPage = max(1, (int) ceil($postRepository->count(['thread' => $thread]) / self::POSTS_PER_PAGE));

                $this->notifyReply($thread, $post, $user, $lastPage, $postRepository, $em, $notifications);

                return $this->redirectToRoute('app_thread_show', [
                    'slug' => $thread->getSlug(),
                    'page' => $lastPage,
                    '_fragment' => 'post-' . $post->getId(),
                ]);
            }
        }

        return $this->render('thread/show.html.twig', [
            'thread' => $thread,
            'posts' => $posts,
            'form' => $form?->createView(),
        ]);
    }

    #[Route('/category/{slug}/new-thread', name: 'new')]
    #[IsGranted('ROLE_USER')]
    public function new(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepository,
        SluggerInterface $slugger,
        GamificationService $gamification
    ): Response {
        $category = $categoryRepository->findOneBy(['slug' => $slug]);

        if (!$category) {
            throw $this->createNotFoundException('Catégorie introuvable');
        }

        // Catégorie de regroupement : refus avant tout traitement du formulaire (GET comme POST forgé)
        if (!$category->isAllowThreads()) {
            $this->addFlash('error', 'La création de sujets est désactivée dans cette catégorie. Choisissez l\'une de ses sous-catégories.');

            return $this->redirectToRoute('app_forum_category', ['slug' => $category->getSlug()]);
        }

        $thread = new Thread();
        $form = $this->createForm(ThreadFormType::class, $thread);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            // Créer le slug
            // Titre slugifié tronqué pour rester sous la limite de 255 caractères de la colonne
            $baseSlug = trim($slugger->slug((string) $thread->getTitle())->lower()->truncate(200)->toString(), '-');
            $thread->setSlug(($baseSlug !== '' ? $baseSlug . '-' : '') . uniqid());
            $thread->setAuthor($user);
            $thread->setCategory($category);

            // Premier post = contenu du thread
            $post = new Post();
            $post->setContent($form->get('content')->getData());
            $post->setAuthor($user);
            $post->setThread($thread);
            $post->setIsFirst(true);

            $em->persist($thread);
            $em->persist($post);
            $em->flush();
            // Pionnier / Maître forgeron de l'auteur
            $gamification->onThreadCreated($thread);

            $this->addFlash('success', 'Sujet créé avec succès !');
            return $this->redirectToRoute('app_thread_show', ['slug' => $thread->getSlug()]);
        }

        return $this->render('thread/new.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }

    /**
     * Notifie l'auteur du sujet et les participants précédents (dédoublonnés, limités) d'une nouvelle réponse.
     * Agrégation par sujet : « 3 nouvelles réponses au sujet … ».
     */
    private function notifyReply(Thread $thread, Post $post, User $author, int $page, PostRepository $postRepository, EntityManagerInterface $em, NotificationService $notifications): void
    {
        $url = $this->generateUrl('app_thread_show', [
            'slug' => $thread->getSlug(),
            'page' => $page,
            '_fragment' => 'post-' . $post->getId(),
        ]);
        $key = self::threadNotificationKey($thread);
        $data = ['thread' => $thread->getTitle(), 'threadId' => $thread->getId()];
        $threadAuthor = $thread->getAuthor();

        if ($threadAuthor) {
            $notifications->notify($threadAuthor, Notification::TYPE_FORUM_REPLY, $author, $data + ['own' => true], $url, $key);
        }

        // Participants précédents les plus récents (hors auteur du sujet et auteur de la réponse)
        $excluded = array_filter([$author->getId(), $threadAuthor?->getId()]);
        $participants = $postRepository->createQueryBuilder('p')
            ->select('IDENTITY(p.author) AS authorId', 'MAX(p.createdAt) AS HIDDEN lastPost')
            ->where('p.thread = :thread')
            ->andWhere('p.author NOT IN (:excluded)')
            ->groupBy('p.author')
            ->orderBy('lastPost', 'DESC')
            ->setMaxResults(self::MAX_NOTIFIED_PARTICIPANTS)
            ->setParameter('thread', $thread)
            ->setParameter('excluded', $excluded)
            ->getQuery()
            ->getSingleColumnResult();

        if ($participants) {
            $users = $em->getRepository(User::class)->findBy(['id' => $participants]);
            $notifications->notifyMany($users, Notification::TYPE_FORUM_REPLY, $author, $data + ['own' => false], $url, $key);
        }
    }

    private static function threadNotificationKey(Thread $thread): string
    {
        return 'thread:' . $thread->getId();
    }
}
