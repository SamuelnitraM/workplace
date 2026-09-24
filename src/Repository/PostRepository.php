<?php

namespace App\Repository;

use App\Entity\Post;
use App\Entity\Thread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    //    /**
    //     * @return Post[] Returns an array of Post objects
    //     */
    /** Page number of a post inside its thread (posts ordered by date, then id). */
    public function findPageOfPost(Post $post, int $postsPerPage): int
    {
        $before = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.thread = :thread')
            ->andWhere('p.createdAt < :createdAt OR (p.createdAt = :createdAt AND p.id < :id)')
            ->setParameter('thread', $post->getThread())
            ->setParameter('createdAt', $post->getCreatedAt())
            ->setParameter('id', $post->getId())
            ->getQuery()
            ->getSingleScalarResult();
        return intdiv($before, $postsPerPage) + 1;
    }

    public function findFirstPostOfThread(Thread $thread): ?Post
    {
        return $this->findOneBy(['thread' => $thread, 'isFirst' => true]);
    }
}
