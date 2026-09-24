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
use App\Forum\ForumActivityNotifier;
use App\Repository\ThreadSubscriptionRepository;
use App\Security\Voter\ThreadVoter;
use App\Service\GamificationService;
use App\Service\NotificationService;

#[Route('/forum', name: 'app_thread_')]
class ThreadController extends AbstractController
{
    private const POSTS_PER_PAGE = 10;

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
        ForumActivityNotifier $forumNotifier,
        NotificationRepository $notificationRepository,
        ThreadSubscriptionRepository $subscriptions,
    ): Response {
        $thread = $threadRepository->findOneBy(['slug' => $slug]);

        if (!$thread) {
            throw $this->createNotFoundException('Sujet introuvable');
        }

        // Les réponses et mentions de ce sujet sont affichées : leurs notifications sont lues
        if ($this->getUser() instanceof User) {
            $notificationRepository->markReadByGroupKey($this->getUser(), ForumActivityNotifier::replyGroupKey($thread));
            $notificationRepository->markReadByGroupKey($this->getUser(), ForumActivityNotifier::mentionGroupKey($thread));
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

                // Abonnement de l'auteur, mentions @pseudo, puis abonnés du sujet
                $forumNotifier->onReply($thread, $post, $this->generateUrl('app_thread_show', [
                    'slug' => $thread->getSlug(),
                    'page' => $lastPage,
                    '_fragment' => 'post-' . $post->getId(),
                ]));

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
            'isSubscribed' => $this->getUser() instanceof User && $subscriptions->isSubscribed($this->getUser(), $thread),
            'solutionPage' => $thread->getSolutionPost() ? $this->pageOfPost($thread->getSolutionPost(), $postRepository) : null,
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
        GamificationService $gamification,
        ForumActivityNotifier $forumNotifier,
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
            // Abonnement de l'auteur au sujet et mentions @pseudo du premier message
            $forumNotifier->onThreadCreated($thread, $post, $this->generateUrl('app_thread_show', ['slug' => $thread->getSlug()]));

            $this->addFlash('success', 'Sujet créé avec succès !');
            return $this->redirectToRoute('app_thread_show', ['slug' => $thread->getSlug()]);
        }

        return $this->render('thread/new.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }

    /** Suivre / ne plus suivre un sujet (notifications des nouvelles réponses). */
    #[Route('/thread/{slug}/subscription', name: 'subscription', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function subscription(string $slug, Request $request, ThreadRepository $threadRepository, ThreadSubscriptionRepository $subscriptions): Response
    {
        $thread = $threadRepository->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException('Sujet introuvable');
        if (!$this->isCsrfTokenValid('thread_subscription_' . $thread->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide');
        }
        $this->denyAccessUnlessGranted(ThreadVoter::SUBSCRIBE, $thread);

        /** @var User $user */
        $user = $this->getUser();
        if ($request->request->getBoolean('subscribe')) {
            $subscriptions->subscribe($user, $thread);
            $this->addFlash('success', 'Vous suivez ce sujet : vous serez notifié de chaque nouvelle réponse.');
        } else {
            $subscriptions->unsubscribe($user, $thread);
            $this->addFlash('success', 'Vous ne suivez plus ce sujet.');
        }

        return $this->redirectToRoute('app_thread_show', [
            'slug' => $thread->getSlug(),
            'page' => max(1, $request->request->getInt('page', 1)),
        ]);
    }

    /**
     * Choisir une réponse comme solution du sujet (ou retirer la solution actuelle) : auteur du sujet ou administrateur.
     * L'auteur de la réponse gagne de l'XP (une fois par sujet) et reçoit une notification.
     */
    #[Route('/thread/{slug}/solution/{postId}', name: 'solution', requirements: ['postId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function solution(
        string $slug,
        int $postId,
        Request $request,
        ThreadRepository $threadRepository,
        PostRepository $postRepository,
        EntityManagerInterface $em,
        GamificationService $gamification,
        NotificationService $notifications,
    ): Response {
        $thread = $threadRepository->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException('Sujet introuvable');
        if (!$this->isCsrfTokenValid('thread_solution_' . $postId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide');
        }
        $this->denyAccessUnlessGranted(ThreadVoter::SOLVE, $thread);

        $post = $postRepository->find($postId);
        if (!$post || $post->getThread()?->getId() !== $thread->getId() || $post->isFirst()) {
            throw $this->createNotFoundException('Réponse introuvable');
        }

        $page = $this->pageOfPost($post, $postRepository);
        $redirect = $this->redirectToRoute('app_thread_show', ['slug' => $thread->getSlug(), 'page' => $page, '_fragment' => 'post-' . $post->getId()]);

        if ($thread->getSolutionPost()?->getId() === $post->getId()) {
            $thread->setSolutionPost(null);
            $em->flush();
            $this->addFlash('success', 'La solution a été retirée : le sujet n\'est plus marqué comme résolu.');

            return $redirect;
        }

        $thread->setSolutionPost($post);
        $em->flush();

        /** @var User $user */
        $user = $this->getUser();
        $author = $post->getAuthor();
        if ($author && $author->getId() !== $user->getId()) {
            $awarded = $gamification->onSolutionChosen($post);
            $notifications->notify(
                $author,
                Notification::TYPE_FORUM_SOLUTION,
                $user,
                ['thread' => $thread->getTitle(), 'threadId' => $thread->getId(), 'xp' => $awarded ? GamificationService::SOLUTION_XP : 0],
                $this->generateUrl('app_thread_show', ['slug' => $thread->getSlug(), 'page' => $page, '_fragment' => 'post-' . $post->getId()]),
            );
        }

        $this->addFlash('success', 'Sujet marqué comme résolu. Merci d\'avoir mis en avant la bonne réponse !');

        return $redirect;
    }

    /** Page (1…n) sur laquelle un message est affiché dans son sujet. */
    private function pageOfPost(Post $post, PostRepository $postRepository): int
    {
        $before = (int) $postRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.thread = :thread')
            ->andWhere('p.createdAt < :createdAt OR (p.createdAt = :createdAt AND p.id < :id)')
            ->setParameter('thread', $post->getThread())
            ->setParameter('createdAt', $post->getCreatedAt())
            ->setParameter('id', $post->getId())
            ->getQuery()
            ->getSingleScalarResult();

        return intdiv($before, self::POSTS_PER_PAGE) + 1;
    }
}
