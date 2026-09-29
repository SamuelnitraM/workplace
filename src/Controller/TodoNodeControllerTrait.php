<?php

namespace App\Controller;

use App\Entity\TodoNode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared to-do actions of the personal (TodoController) and group (GroupTodoController) to-do lists:
 * CSRF check of the to-do forms and fetch calls, progress steps of a task.
 */
trait TodoNodeControllerTrait
{
    /** Progress step of a task: +25 %, -25 %, or 100 % at once. */
    private const PROGRESS_INCREMENT = 'increment';
    private const PROGRESS_DECREMENT = 'decrement';
    private const PROGRESS_VALIDATE = 'validate';
    private const PROGRESS_STEP = 25;

    /** CSRF token sent via the _token field (forms) or the X-CSRF-Token header (fetch). */
    private function isTodoCsrfValid(Request $request): bool
    {
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');
        return $this->isCsrfTokenValid('todo', (string) $token);
    }

    /**
     * Applies a progress step to a task (the access right is checked by the caller): reaching 100 % checks the task,
     * going down unchecks it. JSON response with the new state.
     */
    private function progressResponse(TodoNode $task, string $step, EntityManagerInterface $em): JsonResponse
    {
        $progress = match ($step) {
            self::PROGRESS_INCREMENT => min(100, ($task->getProgress() ?? 0) + self::PROGRESS_STEP),
            self::PROGRESS_DECREMENT => max(0, ($task->getProgress() ?? 0) - self::PROGRESS_STEP),
            default => 100,
        };
        $task->setProgress($progress);
        if ($step === self::PROGRESS_DECREMENT) {
            $task->setIsDone(false);
            $task->setDoneAt(null);
        } elseif ($progress === 100) {
            $task->setIsDone(true);
            $task->setDoneAt(new \DateTimeImmutable());
        }
        $em->flush();
        return new JsonResponse([
            'progress' => $progress,
            'isDone' => $task->isDone(),
            'type' => $step,
        ]);
    }
}
