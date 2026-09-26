<?php

namespace App\Feed;

use App\Entity\ArmyList;
use App\Entity\Badge;
use App\Entity\GalleryPhoto;
use App\Entity\Post;
use App\Entity\Thread;
use App\Entity\User;
use App\Repository\GalleryPhotoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fil d'actualité de l'accueil : activité PUBLIQUE des amis et des membres des groupes du membre connecté.
 *
 * Sources (uniquement du contenu déjà public ailleurs sur le site) :
 *  - photos visibles de la galerie ;
 *  - nouveaux sujets du forum ;
 *  - listes d'armée publiques ;
 *  - badges obtenus (regroupés par membre et par jour : « a obtenu 3 badges »).
 *
 * Filtres : « tout » (amis + groupes), « amis », « groupes », « communaute » (tous les membres),
 * « actualites » (sujets des catégories en lecture seule du forum : annonces et nouveautés du site).
 * Les activités du membre lui-même ne sont pas affichées, sauf dans les actualités.
 *
 * Pagination par curseur (« Voir plus ») : date du dernier élément affiché + clés des éléments de même date
 * déjà affichés (évite de sauter ou répéter des éléments créés à la même seconde, ex. badges simultanés).
 */
final class FeedService
{
    public const FILTERS = [
        'tout' => 'Tout',
        'amis' => 'Amis',
        'groupes' => 'Groupes',
        'communaute' => 'Communauté',
        self::NEWS_FILTER => 'Actualités',
    ];
    public const NEWS_FILTER = 'actualites';
    public const DEFAULT_FILTER = 'tout';
    public const PER_PAGE = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GalleryPhotoRepository $galleryPhotoRepository,
    ) {
    }

    public static function normalizeFilter(?string $filter): ?string
    {
        return $filter !== null && isset(self::FILTERS[$filter]) ? $filter : null;
    }

    /** Le membre a-t-il des amis ou des co-membres de groupe (sinon, le filtre par défaut est « communauté ») ? */
    public function hasNetwork(User $user): bool
    {
        return $this->actorIds($user, 'tout') !== [];
    }

    /**
     * @return array{items: FeedItem[], next: ?string, filter: string}
     */
    public function page(User $user, string $filter, ?string $cursor = null, int $limit = self::PER_PAGE): array
    {
        $actorIds = in_array($filter, ['communaute', self::NEWS_FILTER], true) ? null : $this->actorIds($user, $filter);
        if ($actorIds === []) {
            return ['items' => [], 'next' => null, 'filter' => $filter];
        }

        [$before, $seen] = self::decodeCursor($cursor);
        $fetch = $limit + 1 + count($seen);

        $items = $filter === self::NEWS_FILTER
            ? $this->news($before, $fetch)
            : array_merge(
                $this->photos($user, $actorIds, $before, $fetch),
                $this->threads($user, $actorIds, $before, $fetch),
                $this->armyLists($user, $actorIds, $before, $fetch),
                $this->badges($user, $actorIds, $before, $fetch),
            );
        $items = array_values(array_filter($items, static fn (FeedItem $item) => !in_array($item->key, $seen, true)));
        usort($items, static fn (FeedItem $a, FeedItem $b) => [$b->date, $b->key] <=> [$a->date, $a->key]);

        $hasMore = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        $this->hydrate($user, $items);

        return ['items' => $items, 'next' => $hasMore && $items !== [] ? self::encodeCursor($items, $cursor) : null, 'filter' => $filter];
    }

    /**
     * Identifiants des membres dont l'activité est affichée (null = tout le monde).
     *
     * @return int[]
     */
    private function actorIds(User $user, string $filter): array
    {
        $connection = $this->em->getConnection();
        $ids = [];

        if ($filter === 'tout' || $filter === 'amis') {
            $ids = array_merge($ids, $connection->fetchFirstColumn(
                "SELECT CASE WHEN requester_id = :me THEN receiver_id ELSE requester_id END FROM friendship
                 WHERE status = 'accepted' AND (requester_id = :me OR receiver_id = :me)",
                ['me' => $user->getId()]
            ));
        }
        if ($filter === 'tout' || $filter === 'groupes') {
            $ids = array_merge($ids, $connection->fetchFirstColumn(
                'SELECT DISTINCT other.user_id FROM group_member mine
                 INNER JOIN group_member other ON other.usergroup_id = mine.usergroup_id
                 WHERE mine.user_id = :me AND other.user_id <> :me',
                ['me' => $user->getId()]
            ));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return FeedItem[] */
    private function photos(User $me, ?array $actorIds, \DateTimeImmutable $before, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p', 'o', 'otb')
            ->from(GalleryPhoto::class, 'p')
            ->innerJoin('p.owner', 'o')
            ->leftJoin('o.titleBadge', 'otb')
            ->where('p.isVisible = true')
            ->andWhere('p.createdAt <= :before')
            ->andWhere('o <> :me')
            ->setParameter('before', $before)
            ->setParameter('me', $me)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit);
        $this->restrictActors($qb, 'o', $actorIds);

        return array_map(
            static fn (GalleryPhoto $photo) => new FeedItem(FeedItem::TYPE_PHOTO, 'photo-' . $photo->getId(), $photo->getCreatedAt(), $photo->getOwner(), $photo),
            $qb->getQuery()->getResult()
        );
    }

    /** @return FeedItem[] */
    private function threads(User $me, ?array $actorIds, \DateTimeImmutable $before, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t', 'a', 'c', 'atb')
            ->from(Thread::class, 't')
            ->innerJoin('t.author', 'a')
            ->leftJoin('a.titleBadge', 'atb')
            ->innerJoin('t.category', 'c')
            ->where('t.createdAt <= :before')
            ->andWhere('a <> :me')
            ->setParameter('before', $before)
            ->setParameter('me', $me)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit);
        $this->restrictActors($qb, 'a', $actorIds);

        return array_map(
            static fn (Thread $thread) => new FeedItem(FeedItem::TYPE_THREAD, 'thread-' . $thread->getId(), $thread->getCreatedAt(), $thread->getAuthor(), $thread),
            $qb->getQuery()->getResult()
        );
    }

    /**
     * Site news: threads of the read-only forum categories, whoever published them.
     *
     * @return FeedItem[]
     */
    private function news(\DateTimeImmutable $before, int $limit): array
    {
        $threads = $this->em->createQueryBuilder()
            ->select('t', 'a', 'c', 'atb')
            ->from(Thread::class, 't')
            ->innerJoin('t.author', 'a')
            ->leftJoin('a.titleBadge', 'atb')
            ->innerJoin('t.category', 'c')
            ->where('c.readOnly = true')
            ->andWhere('t.createdAt <= :before')
            ->setParameter('before', $before)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (Thread $thread) => new FeedItem(FeedItem::TYPE_NEWS, 'news-' . $thread->getId(), $thread->getCreatedAt(), $thread->getAuthor(), $thread),
            $threads
        );
    }

    /** @return FeedItem[] */
    private function armyLists(User $me, ?array $actorIds, \DateTimeImmutable $before, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('l', 'o', 'otb')
            ->from(ArmyList::class, 'l')
            ->innerJoin('l.owner', 'o')
            ->leftJoin('o.titleBadge', 'otb')
            ->where('l.isPublic = true')
            ->andWhere('l.createdAt <= :before')
            ->andWhere('o <> :me')
            ->setParameter('before', $before)
            ->setParameter('me', $me)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit);
        $this->restrictActors($qb, 'o', $actorIds);

        return array_map(
            static fn (ArmyList $list) => new FeedItem(FeedItem::TYPE_ARMY, 'army-' . $list->getId(), $list->getCreatedAt(), $list->getOwner(), $list),
            $qb->getQuery()->getResult()
        );
    }

    /**
     * Badges obtenus, regroupés par membre et par jour.
     *
     * @return FeedItem[]
     */
    private function badges(User $me, ?array $actorIds, \DateTimeImmutable $before, int $limit): array
    {
        $params = ['me' => $me->getId(), 'before' => $before->format('Y-m-d H:i:s'), 'limit' => $limit];
        $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER];
        $actorSql = '';
        if ($actorIds !== null) {
            $actorSql = ' AND ub.user_id IN (:actors)';
            $params['actors'] = $actorIds;
            $types['actors'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT ub.user_id, DATE(ub.unlocked_at) AS day, MAX(ub.unlocked_at) AS last_at,
                    GROUP_CONCAT(ub.badge_id ORDER BY ub.unlocked_at DESC, ub.badge_id DESC) AS badge_ids
             FROM user_badge ub
             WHERE ub.user_id <> :me' . $actorSql . '
             GROUP BY ub.user_id, DATE(ub.unlocked_at)
             HAVING MAX(ub.unlocked_at) <= :before
             ORDER BY last_at DESC
             LIMIT :limit',
            $params,
            $types
        );
        if ($rows === []) {
            return [];
        }

        $users = $this->indexById($this->em->getRepository(User::class)->findBy(['id' => array_unique(array_column($rows, 'user_id'))]));
        $badgeIds = array_unique(array_merge(...array_map(static fn (array $row) => explode(',', $row['badge_ids']), $rows)));
        $badges = $this->indexById($this->em->getRepository(Badge::class)->findBy(['id' => $badgeIds]));

        $items = [];
        foreach ($rows as $row) {
            $user = $users[(int) $row['user_id']] ?? null;
            if (!$user) {
                continue;
            }
            $rowBadges = array_values(array_filter(array_map(static fn (string $id) => $badges[(int) $id] ?? null, explode(',', $row['badge_ids']))));
            $items[] = new FeedItem(
                FeedItem::TYPE_BADGES,
                'badges-' . $row['user_id'] . '-' . $row['day'],
                new \DateTimeImmutable($row['last_at']),
                $user,
                $rowBadges,
            );
        }

        return $items;
    }

    private function restrictActors(\Doctrine\ORM\QueryBuilder $qb, string $alias, ?array $actorIds): void
    {
        if ($actorIds !== null) {
            $qb->andWhere($alias . '.id IN (:actors)')->setParameter('actors', $actorIds);
        }
    }

    /**
     * Compléments d'affichage en requêtes groupées : likes / commentaires des photos (et « aimée par moi »),
     * nombre de réponses et extrait des sujets.
     *
     * @param FeedItem[] $items
     */
    private function hydrate(User $me, array $items): void
    {
        $photos = [];
        $threads = [];
        foreach ($items as $item) {
            if ($item->type === FeedItem::TYPE_PHOTO) {
                $photos[$item->subject->getId()] = $item->subject;
            } elseif ($item->isThread()) {
                $threads[$item->subject->getId()] = $item->subject;
            }
        }

        if ($photos !== []) {
            $stats = $this->galleryPhotoRepository->getStatsForPhotos(array_values($photos));
            $liked = array_map('intval', $this->em->getConnection()->fetchFirstColumn(
                'SELECT photo_id FROM gallery_photo_like WHERE user_id = :me AND photo_id IN (:ids)',
                ['me' => $me->getId(), 'ids' => array_keys($photos)],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
            ));
            foreach ($items as $item) {
                if ($item->type === FeedItem::TYPE_PHOTO) {
                    $id = $item->subject->getId();
                    $item->meta = ($stats[$id] ?? ['likes' => 0, 'comments' => 0]) + ['liked' => in_array($id, $liked, true)];
                }
            }
        }

        if ($threads !== []) {
            $rows = $this->em->createQueryBuilder()
                ->select('IDENTITY(p.thread) AS threadId', 'COUNT(p.id) AS total')
                ->from(Post::class, 'p')
                ->where('p.thread IN (:threads)')
                ->setParameter('threads', array_keys($threads))
                ->groupBy('p.thread')
                ->getQuery()
                ->getArrayResult();
            $counts = array_column($rows, 'total', 'threadId');

            $firstPosts = $this->em->createQueryBuilder()
                ->select('p')
                ->from(Post::class, 'p')
                ->where('p.thread IN (:threads)')
                ->andWhere('p.isFirst = true')
                ->setParameter('threads', array_keys($threads))
                ->getQuery()
                ->getResult();
            $contents = [];
            foreach ($firstPosts as $post) {
                // An opening post hidden by moderation shows no excerpt
                $contents[$post->getThread()->getId()] = $post->isHiddenByModeration() ? '' : (string) $post->getContent();
            }

            foreach ($items as $item) {
                if ($item->isThread()) {
                    $id = $item->subject->getId();
                    $item->meta = [
                        'replies' => max(0, (int) ($counts[$id] ?? 1) - 1),
                        'content' => $contents[$id] ?? '',
                    ];
                }
            }
        }
    }

    /** @param FeedItem[] $items */
    private static function encodeCursor(array $items, ?string $previous): string
    {
        $last = end($items);
        $keys = [];
        foreach ($items as $item) {
            if ($item->date == $last->date) {
                $keys[] = $item->key;
            }
        }
        // Éléments de même date déjà vus sur les pages précédentes
        [$previousDate, $previousKeys] = self::decodeCursor($previous);
        if ($previous !== null && $previousDate == $last->date) {
            $keys = array_merge($previousKeys, $keys);
        }

        return rtrim(strtr(base64_encode($last->date->format('Y-m-d H:i:s') . '|' . implode(',', array_unique($keys))), '+/', '-_'), '=');
    }

    /** @return array{0: \DateTimeImmutable, 1: string[]} */
    private static function decodeCursor(?string $cursor): array
    {
        $now = new \DateTimeImmutable('+1 minute');
        if ($cursor === null || $cursor === '') {
            return [$now, []];
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || !str_contains($raw, '|')) {
            return [$now, []];
        }
        [$date, $keys] = explode('|', $raw, 2);
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date);
        if ($parsed === false) {
            return [$now, []];
        }
        $keys = array_values(array_filter(explode(',', $keys), static fn (string $k) => (bool) preg_match('/^[a-z]+-[0-9-]+$/D', $k)));

        return [$parsed, array_slice($keys, 0, 200)];
    }

    /**
     * @template T of object
     * @param T[] $entities
     * @return array<int, T>
     */
    private function indexById(array $entities): array
    {
        $indexed = [];
        foreach ($entities as $entity) {
            $indexed[$entity->getId()] = $entity;
        }

        return $indexed;
    }
}
