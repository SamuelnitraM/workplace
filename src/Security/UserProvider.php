<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findOneByEmailOrUsername($identifier);

        if (!$user) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof \App\Entity\User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $refreshedUser = $user->getId() !== null ? $this->userRepository->find($user->getId()) : null;
        if (!$refreshedUser) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        return $refreshedUser;
    }

    public function supportsClass(string $class): bool
    {
        return \App\Entity\User::class === $class || is_subclass_of($class, \App\Entity\User::class);
    }
}