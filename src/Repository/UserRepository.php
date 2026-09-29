<?php

namespace App\Repository;

use App\Entity\User;
use App\Moderation\BannedMembers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Number of registrations per day since the given day, days without registration left out.
     *
     * @return array<string, int> keyed by Y-m-d
     */
    public function countRegistrationsPerDay(\DateTimeImmutable $firstDay): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT DATE(created_at) AS day, COUNT(*) AS total FROM user WHERE created_at >= :first GROUP BY DATE(created_at)',
            ['first' => $firstDay->format('Y-m-d 00:00:00')],
        );
        return array_map('intval', $rows);
    }

    /** « Membre supprimé » account holding the anonymised contributions of deleted members, created at first need. */
    public function deletedMemberAccount(): User
    {
        $account = $this->findOneBy(['email' => User::DELETED_MEMBER_EMAIL]);
        if ($account !== null) {
            return $account;
        }
        $account = (new User())
            ->setUsername(User::DELETED_MEMBER_USERNAME)
            ->setEmail(User::DELETED_MEMBER_EMAIL)
            ->setPassword('!')
            ->setRoles([])
            ->setIsVerified(true)
            ->setOnboardingCompletedAt(new \DateTimeImmutable());
        // Suspended for good: never logs in, left out of every list like a banned member (App\Moderation\BannedMembers)
        $account->suspend(null, 'Compte technique des contributions anonymisées');
        $this->getEntityManager()->persist($account);
        $this->getEntityManager()->flush();
        return $account;
    }

    /**
     * An identifier containing « @ » is always treated as an e-mail address (a username cannot contain one),
     * otherwise as a username: a username equal to another member's e-mail address cannot hijack the login.
     */
    public function findOneByEmailOrUsername(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return $this->findOneBy(['email' => $identifier]);
        }

        return $this->findOneBy(['username' => $identifier]);
    }

    /**
     * Members whose username contains the query, banned members left out.
     *
     * @return User[]
     */
    public function searchByUsername(string $query): array
    {
        return BannedMembers::exclude($this->createQueryBuilder('u'), 'u')
            ->andWhere('u.username LIKE :query')
            ->setParameter('query', '%' . addcslashes($query, '%_\\') . '%')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Mention suggestions (@username): usernames STARTING with the input, shortest first then alphabetically;
     * banned members are left out.
     *
     * @return User[]
     */
    public function findMentionSuggestions(string $prefix, int $limit = 8): array
    {
        return BannedMembers::exclude($this->createQueryBuilder('u'), 'u')
            ->addSelect('LENGTH(u.username) AS HIDDEN usernameLength')
            ->andWhere('u.username LIKE :prefix')
            ->setParameter('prefix', addcslashes($prefix, '%_\\') . '%')
            ->orderBy('usernameLength', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Members matching usernames (case-insensitive comparison, according to the database collation).
     *
     * @param string[] $usernames
     * @return User[]
     */
    public function findByUsernames(array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.username IN (:usernames)')
            ->setParameter('usernames', array_values(array_unique($usernames)))
            ->getQuery()
            ->getResult();
    }
}
