<?php

namespace App\Repository;

use App\Entity\PrivateConversation;
use App\Entity\PrivateMessage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivateMessage>
 */
class PrivateMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivateMessage::class);
    }

    /** Nombre total de messages non lus reçus par l'utilisateur. */
    public function countUnreadFor(User $user): int
    {
        return (int) $this->unreadQuery($user)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Messages non lus reçus, groupés par conversation.
     *
     * @return array<int, int> [conversationId => nombre]
     */
    public function countUnreadByConversation(User $user): array
    {
        $rows = $this->unreadQuery($user)
            ->select('IDENTITY(m.conversation) AS conversationId, COUNT(m.id) AS unread')
            ->groupBy('m.conversation')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(fn ($r) => [(int) $r['conversationId'], (int) $r['unread']], $rows), 1, 0);
    }

    /** Marque comme lus les messages reçus par l'utilisateur dans une conversation. */
    public function markConversationAsRead(PrivateConversation $conversation, User $reader): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.isRead', 'true')
            ->where('m.conversation = :conversation')
            ->andWhere('m.author != :reader')
            ->andWhere('m.isRead = false')
            ->setParameter('conversation', $conversation)
            ->setParameter('reader', $reader)
            ->getQuery()
            ->execute();
    }

    /**
     * Dernier message de chaque conversation, en une seule requête.
     *
     * @param PrivateConversation[] $conversations
     * @return array<int, PrivateMessage> [conversationId => message]
     */
    public function findLastMessages(array $conversations): array
    {
        if (!$conversations) {
            return [];
        }

        $messages = $this->createQueryBuilder('m')
            ->where('m.id IN (SELECT MAX(m2.id) FROM '.PrivateMessage::class.' m2 WHERE m2.conversation IN (:conversations) GROUP BY m2.conversation)')
            ->setParameter('conversations', $conversations)
            ->getQuery()
            ->getResult();

        $byConversation = [];
        foreach ($messages as $message) {
            $byConversation[$message->getConversation()->getId()] = $message;
        }

        return $byConversation;
    }
    private function unreadQuery(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->join('m.conversation', 'c')
            ->where('c.participant1 = :user OR c.participant2 = :user')
            ->andWhere('m.author != :user')
            ->andWhere('m.isRead = false')
            ->setParameter('user', $user);
    }
}
