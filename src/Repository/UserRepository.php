<?php

namespace App\Repository;

use App\Entity\User;
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
     * Un identifiant contenant « @ » est toujours traité comme un email (un pseudo ne peut pas en contenir),
     * sinon comme un pseudo : un pseudo égal à l'email d'un autre membre ne peut donc pas détourner la connexion.
     */
    public function findOneByEmailOrUsername(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return $this->findOneBy(['email' => $identifier]);
        }

        return $this->findOneBy(['username' => $identifier]);
    }

    public function searchByUsername(string $query): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.username LIKE :query')
            ->setParameter('query', '%' . addcslashes($query, '%_\\') . '%')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Suggestions de mention (@pseudo) : pseudos COMMENÇANT par la saisie, triés par longueur puis ordre alphabétique.
     *
     * @return User[]
     */
    public function findMentionSuggestions(string $prefix, int $limit = 8): array
    {
        return $this->createQueryBuilder('u')
            ->addSelect('LENGTH(u.username) AS HIDDEN usernameLength')
            ->where('u.username LIKE :prefix')
            ->setParameter('prefix', addcslashes($prefix, '%_\\') . '%')
            ->orderBy('usernameLength', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Membres correspondant à des pseudos (comparaison insensible à la casse, selon la collation de la base).
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

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
