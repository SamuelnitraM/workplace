<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Security\Voter\MemberSanctionVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

class MemberSanctionVoterTest extends TestCase
{
    /** @return iterable<string, array{list<string>, list<string>, int}> */
    public static function sanctionCases(): iterable
    {
        yield 'moderator sanctions a member' => [['ROLE_MODERATOR'], [], VoterInterface::ACCESS_GRANTED];
        yield 'moderator cannot sanction a moderator' => [['ROLE_MODERATOR'], ['ROLE_MODERATOR'], VoterInterface::ACCESS_DENIED];
        yield 'moderator cannot sanction an administrator' => [['ROLE_MODERATOR'], ['ROLE_ADMIN'], VoterInterface::ACCESS_DENIED];
        yield 'administrator sanctions a moderator' => [['ROLE_ADMIN'], ['ROLE_MODERATOR'], VoterInterface::ACCESS_GRANTED];
        yield 'administrator cannot sanction an administrator' => [['ROLE_ADMIN'], ['ROLE_ADMIN'], VoterInterface::ACCESS_DENIED];
        yield 'member cannot sanction anyone' => [[], [], VoterInterface::ACCESS_DENIED];
    }

    /**
     * @param list<string> $actorRoles
     * @param list<string> $memberRoles
     */
    #[DataProvider('sanctionCases')]
    public function testSanctionRights(array $actorRoles, array $memberRoles, int $expectedVote): void
    {
        $voter = new MemberSanctionVoter(new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_MODERATOR']]));
        $actor = $this->member(1, $actorRoles);
        $token = new UsernamePasswordToken($actor, 'main', $actor->getRoles());
        self::assertSame($expectedVote, $voter->vote($token, $this->member(2, $memberRoles), [MemberSanctionVoter::SANCTION]));
    }

    public function testNobodySanctionsThemselves(): void
    {
        $voter = new MemberSanctionVoter(new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_MODERATOR']]));
        $admin = $this->member(1, ['ROLE_ADMIN']);
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $admin, [MemberSanctionVoter::SANCTION]));
    }

    /** @param list<string> $roles */
    private function member(int $id, array $roles): User
    {
        $user = (new User())->setUsername('member' . $id)->setEmail('member' . $id . '@example.test')->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        return $user;
    }
}
