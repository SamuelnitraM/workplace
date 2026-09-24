<?php

namespace App\Forum;

use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\Thread;
use App\Entity\User;
use App\Repository\ThreadSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Abonnements et notifications du forum après l'écriture d'un message :
 *  - l'auteur du message est abonné au sujet (il suivra les réponses) ;
 *  - les membres mentionnés (@pseudo) reçoivent une notification « mention » ;
 *  - les autres abonnés reçoivent une notification « réponse » (agrégée par sujet).
 * Un membre mentionné ET abonné ne reçoit que la mention (plus précise).
 */
final class ForumActivityNotifier
{
    public function __construct(
        private readonly ThreadSubscriptionRepository $subscriptions,
        private readonly UserRepository $userRepository,
        private readonly NotificationService $notifications,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function replyGroupKey(Thread $thread): string
    {
        return 'thread:' . $thread->getId();
    }

    public static function mentionGroupKey(Thread $thread): string
    {
        return 'mention:thread:' . $thread->getId();
    }

    /** Premier message d'un nouveau sujet : abonnement de l'auteur et mentions. */
    public function onThreadCreated(Thread $thread, Post $firstPost, string $url): void
    {
        $author = $firstPost->getAuthor();
        if (!$author) {
            return;
        }
        $this->subscriptions->subscribe($author, $thread);
        $this->notifyMentions($thread, $firstPost, $author, $url);
    }

    /** Nouvelle réponse : abonnement de l'auteur, mentions, puis abonnés. */
    public function onReply(Thread $thread, Post $post, string $url): void
    {
        $author = $post->getAuthor();
        if (!$author) {
            return;
        }
        $this->subscriptions->subscribe($author, $thread);
        $mentionedIds = $this->notifyMentions($thread, $post, $author, $url);

        $subscribers = $this->subscriptions->findSubscribers($thread, array_merge([$author->getId()], $mentionedIds));
        if ($subscribers === []) {
            return;
        }

        $data = ['thread' => $thread->getTitle(), 'threadId' => $thread->getId()];
        $threadAuthorId = $thread->getAuthor()?->getId();
        $owner = array_filter($subscribers, static fn (User $u) => $u->getId() === $threadAuthorId);
        $others = array_filter($subscribers, static fn (User $u) => $u->getId() !== $threadAuthorId);
        $key = self::replyGroupKey($thread);

        if ($owner !== []) {
            $this->notifications->notifyMany($owner, Notification::TYPE_FORUM_REPLY, $author, $data + ['own' => true], $url, $key, false);
        }
        if ($others !== []) {
            $this->notifications->notifyMany($others, Notification::TYPE_FORUM_REPLY, $author, $data + ['own' => false], $url, $key, false);
        }
        $this->em->flush();
    }

    /**
     * Notifie les membres mentionnés dans le message (hors auteur, limité).
     *
     * @return int[] identifiants des membres notifiés
     */
    private function notifyMentions(Thread $thread, Post $post, User $author, string $url): array
    {
        $usernames = array_slice(ForumMarkdown::extractMentions((string) $post->getContent()), 0, ForumMarkdown::MAX_NOTIFIED_MENTIONS * 2);
        if ($usernames === []) {
            return [];
        }

        $users = array_filter(
            $this->userRepository->findByUsernames($usernames),
            static fn (User $u) => $u->getId() !== $author->getId()
        );
        $users = array_slice(array_values($users), 0, ForumMarkdown::MAX_NOTIFIED_MENTIONS);
        if ($users === []) {
            return [];
        }

        $this->notifications->notifyMany(
            $users,
            Notification::TYPE_FORUM_MENTION,
            $author,
            ['thread' => $thread->getTitle(), 'threadId' => $thread->getId()],
            $url,
            self::mentionGroupKey($thread),
        );

        return array_map(static fn (User $u) => (int) $u->getId(), $users);
    }
}
