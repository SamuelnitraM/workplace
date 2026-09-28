<?php

namespace App\Service;

use App\Entity\Notification;

/**
 * Rendering of the notifications (plain French text, icon and tone), used by the Twig pages and by the
 * real-time payload. The text is NEVER HTML: Twig escapes it on the server and it is inserted
 * with textContent in the browser.
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
                ? sprintf('%d nouveaux badges obtenus, dont %s', $count, $this->str($data, 'badge'))
                : sprintf('Nouveau badge obtenu : %s', $this->str($data, 'badge')),

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

            Notification::TYPE_MODERATION_NOTICE => $this->str($data, 'message'),

            Notification::TYPE_GROUP_MENTION => $count > 1
                ? sprintf('%d mentions de votre pseudo dans le salon #%s de %s', $count, $this->str($data, 'channel'), $this->str($data, 'group'))
                : sprintf('%s vous a mentionné dans le salon #%s de %s', $actor, $this->str($data, 'channel'), $this->str($data, 'group')),

            Notification::TYPE_TODO_ASSIGNMENT => $this->todoAssignment($data, $actor, $count),

            default => 'Nouvelle notification',
        };
    }

    /**
     * SVG icon (name in templates/_partials/_icon.html.twig) and tone (design system colour) of each type.
     * The icons are also in the SVG sprite used by the notification menu (_partials/_icon_sprite.html.twig).
     */
    private const PRESENTATION = [
        Notification::TYPE_FRIEND_REQUEST => ['user-plus', 'primary'],
        Notification::TYPE_FRIEND_ACCEPTED => ['user-check', 'success'],
        Notification::TYPE_GROUP_INVITATION => ['mail', 'primary'],
        Notification::TYPE_GROUP_INVITATION_ACCEPTED => ['users', 'success'],
        Notification::TYPE_GROUP_MESSAGE => ['messages-square', 'info'],
        Notification::TYPE_FORUM_REPLY => ['message-circle', 'info'],
        Notification::TYPE_PHOTO_LIKE => ['heart', 'danger'],
        Notification::TYPE_PHOTO_COMMENT => ['message-square', 'info'],
        Notification::TYPE_BADGE_EARNED => ['medal', 'accent'],
        Notification::TYPE_LEVEL_UP => ['sparkles', 'accent'],
        Notification::TYPE_FORUM_MENTION => ['at-sign', 'primary'],
        Notification::TYPE_FORUM_SOLUTION => ['check-circle', 'success'],
        Notification::TYPE_MODERATION_NOTICE => ['shield', 'danger'],
        Notification::TYPE_GROUP_MENTION => ['at-sign', 'primary'],
        Notification::TYPE_TODO_ASSIGNMENT => ['clipboard-list', 'primary'],
    ];
    private const DEFAULT_PRESENTATION = ['bell', 'primary'];

    public function icon(Notification $notification): string
    {
        return (self::PRESENTATION[$notification->getType()] ?? self::DEFAULT_PRESENTATION)[0];
    }

    public function tone(Notification $notification): string
    {
        return (self::PRESENTATION[$notification->getType()] ?? self::DEFAULT_PRESENTATION)[1];
    }

    /** @return list<string> every icon a notification can show */
    public static function iconNames(): array
    {
        return array_values(array_unique([...array_column(self::PRESENTATION, 0), self::DEFAULT_PRESENTATION[0]]));
    }

    /** Task assignment: "outcome" is requested, accepted, refused or assigned. */
    private function todoAssignment(array $data, string $actor, int $count): string
    {
        $task = $this->str($data, 'task');
        $group = $this->str($data, 'group');
        return match ($data['outcome'] ?? '') {
            'requested' => $count > 1
                ? sprintf('%d demandes d\'assignation à valider dans les tâches de %s', $count, $group)
                : sprintf('%s demande à être assigné à « %s » (%s)', $actor, $task, $group),
            'accepted' => sprintf('%s a accepté votre demande : vous êtes assigné à « %s » (%s)', $actor, $task, $group),
            'refused' => sprintf('%s a refusé votre demande d\'assignation à « %s » (%s)', $actor, $task, $group),
            default => sprintf('%s vous a assigné à « %s » (%s)', $actor, $task, $group),
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
