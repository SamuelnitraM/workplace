<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Thread;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional test base: empty database before each test and fixture helpers.
 * The test database is created once with "doctrine:database:create --env=test" and "doctrine:schema:create --env=test".
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected const PASSWORD = 'correct horse battery staple';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Login throttling counters survive between runs in the test cache
        static::getContainer()->get('cache.rate_limiter')->clear();
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($connection->createSchemaManager()->listTableNames() as $tableName) {
            if ($tableName !== 'doctrine_migration_versions') {
                $connection->executeStatement('TRUNCATE TABLE `' . $tableName . '`');
            }
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $roles */
    protected function createMember(string $username, array $roles = [], bool $verified = true): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($username . '@example.test')
            ->setRoles($roles)
            ->setIsVerified($verified)
            ->setOnboardingCompletedAt(new \DateTimeImmutable());
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();
        return $user;
    }

    /** Creates a thread with its opening post and one reply, returns the reply. */
    protected function createThreadWithReply(User $author, User $replier): Post
    {
        $category = (new Category())->setName('Peinture')->setSlug('peinture')->setPosition(1)->setCreatedAt(new \DateTimeImmutable());
        $thread = (new Thread())->setTitle('Sous-couche')->setSlug('sous-couche-' . uniqid())->setCategory($category)->setAuthor($author);
        $openingPost = (new Post())->setContent('Quelle sous-couche ?')->setAuthor($author)->setThread($thread)->setIsFirst(true);
        $reply = (new Post())->setContent('Contenu insultant')->setAuthor($replier)->setThread($thread)->setIsFirst(false);
        foreach ([$category, $thread, $openingPost, $reply] as $entity) {
            $this->entityManager()->persist($entity);
        }
        $this->entityManager()->flush();
        return $reply;
    }

    protected function reload(User $user): User
    {
        $this->entityManager()->clear();
        return $this->entityManager()->find(User::class, $user->getId());
    }
}
