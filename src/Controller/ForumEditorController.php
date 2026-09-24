<?php

namespace App\Controller;

use App\Entity\User;
use App\Forum\ForumMarkdown;
use App\Repository\UserRepository;
use App\Service\ForumImageUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Points d'accès de l'éditeur Markdown du forum (contrôleur Stimulus « markdown-editor ») :
 * aperçu, envoi d'image et suggestions de mention. Réservés aux membres connectés.
 */
#[Route('/forum/editor', name: 'app_forum_editor_')]
#[IsGranted('ROLE_USER')]
final class ForumEditorController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'forum_editor';

    /** Taille maximale d'un message prévisualisé (au-delà, l'aperçu est refusé). */
    private const PREVIEW_MAX_LENGTH = 50000;

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request, ForumMarkdown $markdown): JsonResponse
    {
        if ($error = $this->checkCsrf($request)) {
            return $error;
        }
        $content = (string) $request->request->get('content', '');
        if (mb_strlen($content) > self::PREVIEW_MAX_LENGTH) {
            return new JsonResponse(['error' => 'Message trop long pour l’aperçu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['html' => trim($content) === '' ? '' : $markdown->toHtml($content)]);
    }

    #[Route('/image', name: 'image', methods: ['POST'])]
    public function image(Request $request, ForumImageUploader $uploader): JsonResponse
    {
        if ($error = $this->checkCsrf($request)) {
            return $error;
        }
        /** @var User $user */
        $user = $this->getUser();
        $result = $uploader->upload($user, $request->files->get('image'));

        return isset($result['error'])
            ? new JsonResponse(['error' => $result['error']], Response::HTTP_UNPROCESSABLE_ENTITY)
            : new JsonResponse(['url' => $result['url']]);
    }

    #[Route('/mentions', name: 'mentions', methods: ['GET'])]
    public function mentions(Request $request, UserRepository $userRepository): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if ($query === '' || !preg_match('/^[A-Za-z0-9_.-]{1,50}$/D', $query)) {
            return new JsonResponse(['users' => []]);
        }

        $users = array_map(fn (User $user) => [
            'username' => $user->getUsername(),
            'avatar' => $user->getAvatar() ? '/uploads/avatars/' . $user->getAvatar() : null,
        ], $userRepository->findMentionSuggestions($query));

        return new JsonResponse(['users' => $users]);
    }

    private function checkCsrf(Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide : rechargez la page.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
