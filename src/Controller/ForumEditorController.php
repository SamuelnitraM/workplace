<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\PostFormType;
use App\Forum\ForumMarkdown;
use App\Service\ForumImageUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoints of the forum Markdown editor (Stimulus controller « markdown-editor »): preview and image upload.
 * Reserved to signed-in members (mention suggestions: MentionController).
 */
#[Route('/forum/editor', name: 'app_forum_editor_')]
#[IsGranted('ROLE_USER')]
final class ForumEditorController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'forum_editor';

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request, ForumMarkdown $markdown): JsonResponse
    {
        if ($error = $this->checkCsrf($request)) {
            return $error;
        }
        $content = (string) $request->request->get('content', '');
        // Beyond the length accepted at publication, the preview is refused
        if (mb_strlen($content) > PostFormType::CONTENT_MAX_LENGTH) {
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

    private function checkCsrf(Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide : recharge la page.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
