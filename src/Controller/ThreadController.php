<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\PostVote;
use App\Entity\Thread;
use App\Entity\User;
use App\Form\ThreadFormType;
use App\Form\PostFormType;
use App\Repository\CategoryRepository;
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

#[Route('/forum', name: 'app_thread_')]
class ThreadController extends AbstractController
{
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

        /** @var User $user */
        $user = $this->getUser();
        if ($post->getAuthor()?->getId() === $user->getId()) {
            $this->addFlash('warning', 'Vous ne pouvez pas voter pour votre propre réponse.');

            return $this->redirectToRoute('app_thread_show', ['slug' => $slug]);
        }

        $existingVote = null;
        foreach ($post->getVotes() as $vote) {
            if ($vote->getUser()?->getId() === $user->getId() && $vote->getType() === $type) {
                $existingVote = $vote;
                break;
            }
        }

        if ($existingVote) {
            $em->remove($existingVote);
        } else {
            $vote = (new PostVote())
                ->setPost($post)
                ->setUser($user)
                ->setType($type);
            $em->persist($vote);
        }

        $em->flush();
        if (in_array($type, [PostVote::TYPE_POSITIVE, PostVote::TYPE_HELPFUL], true) && $post->getAuthor()) {
            $gamification->syncAllBadges($post->getAuthor());
            $em->flush();
        }

        return $this->redirectToRoute('app_thread_show', ['slug' => $slug]);
    }

    #[Route('/thread/{slug}', name: 'show')]
    public function show(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        ThreadRepository $threadRepository,
        PostRepository $postRepository,
        PaginatorInterface $paginator,
        GamificationService $gamification
    ): Response {
        $thread = $threadRepository->findOneBy(['slug' => $slug]);

        if (!$thread) {
            throw $this->createNotFoundException('Sujet introuvable');
        }

        if ($this->getUser() && $thread->getCreatedAt() < new \DateTimeImmutable('-1 year')) {
            /** @var User $visitor */
            $visitor = $this->getUser();
            $gamification->recordActivity($visitor, 'archaeologist');
            $gamification->syncAllBadges($visitor);
            $em->flush();
        }

        $sessionKey = 'viewed_thread_' . $thread->getId();
        if (!$request->getSession()->has($sessionKey)) {
            $thread->setViews($thread->getViews() + 1);
            $request->getSession()->set($sessionKey, true);
            $em->flush();
        }

        $query = $postRepository->createQueryBuilder('p')
            ->where('p.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery();

        $posts = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            10
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
                if ($post->getCreatedAt()?->format('H:i') >= '05:00' && $post->getCreatedAt()?->format('H:i') <= '06:30') {
                    $gamification->recordActivity($user, 'early_bird');
                }
                if ($post->getCreatedAt()?->format('H:i') >= '02:00' && $post->getCreatedAt()?->format('H:i') <= '04:00') {
                    $gamification->recordActivity($user, 'night_owl');
                }
                if ($thread->getCreatedAt() && $thread->getCreatedAt() >= new \DateTimeImmutable('-24 hours') && $postRepository->count(['thread' => $thread]) === 2) {
                    $gamification->recordActivity($user, 'first_in_class');
                }
                $gamification->syncAllBadges($user);
                $em->flush();

                $this->addFlash('success', 'Réponse ajoutée avec succès !');
                return $this->redirectToRoute('app_thread_show', ['slug' => $thread->getSlug()]);
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

        $thread = new Thread();
        $form = $this->createForm(ThreadFormType::class, $thread);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            // Créer le slug
            $thread->setSlug(strtolower($slugger->slug($thread->getTitle())) . '-' . uniqid());
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
            if ($post->getCreatedAt()?->format('H:i') >= '05:00' && $post->getCreatedAt()?->format('H:i') <= '06:30') {
                $gamification->recordActivity($user, 'early_bird');
            }
            if ($post->getCreatedAt()?->format('H:i') >= '02:00' && $post->getCreatedAt()?->format('H:i') <= '04:00') {
                $gamification->recordActivity($user, 'night_owl');
            }
            $gamification->syncAllBadges($user);
            $em->flush();

            $this->addFlash('success', 'Sujet créé avec succès !');
            return $this->redirectToRoute('app_thread_show', ['slug' => $thread->getSlug()]);
        }

        return $this->render('thread/new.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }
}