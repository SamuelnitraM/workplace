<?php

namespace App\Service;

use App\Entity\Notification;

/**
 * Rendu (texte brut, en français) des notifications : utilisé à la fois par les pages Twig
 * et par la charge utile temps réel. Le texte n'est JAMAIS du HTML : il est échappé par Twig
 * côté serveur et inséré via textContent côté client.
 */
class NotificationRenderer
{
    private const EXCERPT_LENGTH = 80;

    public function text(Notification $notification): string
    {
        $data = $notification->getData();
        $actor = $notification->getActor()?->getUsername() ?? 'Un utilisateur';
        $count = max(1, $notification->getCount());

        return match ($notification->getType()) {
            Notification::TYPE_FRIEND_REQUEST => sprintf('%s vous a envoyé une demande d\'ami', $actor),

            Notification::TYPE_FRIEND_ACCEPTED => sprintf('%s a accepté votre demande d\'ami', $actor),

            Notification::TYPE_GROUP_INVITATION => sprintf(
                '%s vous invite à rejoindre le groupe « %s »',
                $actor,
                $this->str($data, 'group')
            ),

            Notification::TYPE_GROUP_INVITATION_ACCEPTED => sprintf(
                '%s a accepté votre invitation dans le groupe « %s »',
                $actor,
                $this->str($data, 'group')
            ),

            Notification::TYPE_GROUP_MESSAGE => $count > 1
                ? sprintf('%d nouveaux messages dans le salon #%s de %s', $count, $this->str($data, 'channel'), $this->str($data, 'group'))
                : sprintf('%s a écrit dans le salon #%s de %s', $actor, $this->str($data, 'channel'), $this->str($data, 'group')),

            Notification::TYPE_FORUM_REPLY => $this->forumReply($data, $actor, $count),

            Notification::TYPE_PHOTO_LIKE => $count > 1
                ? sprintf('%s et %s ont aimé votre photo', $actor, $this->others($count - 1))
                : sprintf('%s a aimé votre photo', $actor),

            Notification::TYPE_PHOTO_COMMENT => $this->photoComment($data, $actor, $count),

            Notification::TYPE_BADGE_EARNED => $count > 1
                ? sprintf('%d nouveaux badges obtenus, dont %s %s', $count, $this->str($data, 'badgeIcon'), $this->str($data, 'badge'))
                : sprintf('Nouveau badge obtenu : %s %s', $this->str($data, 'badgeIcon'), $this->str($data, 'badge')),

            Notification::TYPE_LEVEL_UP => sprintf('Niveau %d atteint !', (int) ($data['level'] ?? 0)),

            Notification::TYPE_FORUM_MENTION => $count > 1
                ? sprintf('%d mentions de votre pseudo dans « %s »', $count, $this->str($data, 'thread'))
                : sprintf('%s vous a mentionné dans « %s »', $actor, $this->str($data, 'thread')),

            Notification::TYPE_FORUM_SOLUTION => sprintf(
                '%s a choisi votre réponse comme solution de « %s »%s',
                $actor,
                $this->str($data, 'thread'),
                (int) ($data['xp'] ?? 0) > 0 ? sprintf(' (+%d XP)', (int) $data['xp']) : ''
            ),

            default => 'Nouvelle notification',
        };
    }

    /** Icône (emoji) affichée à côté de la notification. */
    public function icon(Notification $notification): string
    {
        return match ($notification->getType()) {
            Notification::TYPE_FRIEND_REQUEST, Notification::TYPE_FRIEND_ACCEPTED => '👥',
            Notification::TYPE_GROUP_INVITATION, Notification::TYPE_GROUP_INVITATION_ACCEPTED => '📨',
            Notification::TYPE_GROUP_MESSAGE => '💬',
            Notification::TYPE_FORUM_REPLY => '🗨️',
            Notification::TYPE_PHOTO_LIKE => '❤️',
            Notification::TYPE_PHOTO_COMMENT => '📝',
            Notification::TYPE_BADGE_EARNED => '🏅',
            Notification::TYPE_LEVEL_UP => '⬆️',
            Notification::TYPE_FORUM_MENTION => '📣',
            Notification::TYPE_FORUM_SOLUTION => '✅',
            default => '🔔',
        };
    }

    /** Extrait court d'un contenu utilisateur (commentaire…) à stocker dans les données. */
    public static function excerpt(string $content): string
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));

        return mb_strlen($content) > self::EXCERPT_LENGTH
            ? rtrim(mb_substr($content, 0, self::EXCERPT_LENGTH - 1)) . '…'
            : $content;
    }

    private function forumReply(array $data, string $actor, int $count): string
    {
        $title = $this->str($data, 'thread');
        $own = (bool) ($data['own'] ?? false);

        if ($count > 1) {
            return sprintf('%d nouvelles réponses %s « %s »', $count, $own ? 'à votre sujet' : 'au sujet', $title);
        }

        return sprintf('%s a répondu %s « %s »', $actor, $own ? 'à votre sujet' : 'au sujet', $title);
    }

    private function photoComment(array $data, string $actor, int $count): string
    {
        $own = (bool) ($data['own'] ?? true);
        $excerpt = $this->str($data, 'excerpt');

        if (!$own) {
            $owner = $this->str($data, 'owner');
            if ($count === 1 && ($data['byOwner'] ?? false)) {
                return sprintf('%s a répondu sous sa photo', $actor);
            }

            return $count > 1
                ? sprintf('%d nouveaux commentaires sur la photo de %s', $count, $owner)
                : sprintf('%s a aussi commenté la photo de %s', $actor, $owner);
        }

        if ($count > 1) {
            return sprintf('%d nouveaux commentaires sur votre photo', $count);
        }

        return $excerpt !== ''
            ? sprintf('%s a commenté votre photo : « %s »', $actor, $excerpt)
            : sprintf('%s a commenté votre photo', $actor);
    }

    private function others(int $n): string
    {
        return $n === 1 ? '1 autre personne' : sprintf('%d autres personnes', $n);
    }

    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
