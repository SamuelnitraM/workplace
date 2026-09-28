<?php

namespace App\Tests\Functional;

use App\Account\InactiveAccountPurger;
use App\Entity\ArmyList;
use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\Post;
use App\Entity\PrivateConversation;
use App\Entity\PrivateMessage;
use App\Entity\Report;
use App\Entity\User;
use App\Moderation\ModerationRecordPurger;
use App\Moderation\ReportReason;
use App\Moderation\ReportResolution;
use App\Moderation\ReportTargetType;

class AccountDeletionTest extends FunctionalTestCase
{
    public function testMemberDeletesTheAccountFromTheSettings(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        $reply = $this->createThreadWithReply($alice, $bob);
        $group = (new Group())->setName('Club')->setSlug('club-' . uniqid())->setCreator($bob);
        $this->entityManager()->persist($group);
        foreach ([[$bob, 'owner', '-3 days'], [$alice, 'member', '-2 days'], [$carol, 'admin', '-1 day']] as [$user, $role, $joined]) {
            $membership = (new GroupMember())->setUser($user)->setUsergroup($group)->setRole($role)->setJoinedAt(new \DateTimeImmutable($joined));
            $group->addMember($membership);
            $this->entityManager()->persist($membership);
        }
        $conversation = (new PrivateConversation())->setParticipant1($bob)->setParticipant2($alice);
        $privateMessage = (new PrivateMessage())->setContent('Salut')->setAuthor($bob);
        $conversation->addMessage($privateMessage);
        $this->entityManager()->persist($conversation);
        $this->entityManager()->persist($privateMessage);
        $this->entityManager()->persist((new ArmyList())->setName('Liste de bob')->setFaction('Ultramarines')->setOwner($bob)->setIsPublic(true)->setCreatedAt(new \DateTimeImmutable()));
        $this->entityManager()->flush();
        $this->client->loginUser($this->reload($bob));
        $crawler = $this->client->request('GET', '/profil/settings/edit');
        $deleteForm = $crawler->selectButton('Supprimer définitivement mon compte')->form(['password' => 'mauvais', 'confirm' => '1']);
        $this->client->submit($deleteForm);
        self::assertResponseRedirects('/profil/settings/edit#suppression');
        self::assertNotNull($this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'bob']));
        $this->client->submit($deleteForm, ['password' => self::PASSWORD]);
        self::assertResponseRedirects('/');
        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), 'Membre supprimé');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'bob']));
        $anonymisedReply = $this->entityManager()->find(Post::class, $reply->getId());
        self::assertSame(User::DELETED_MEMBER_USERNAME, $anonymisedReply->getAuthor()->getUsername());
        self::assertSame(0, $this->entityManager()->getRepository(PrivateConversation::class)->count([]));
        self::assertSame(0, $this->entityManager()->getRepository(ArmyList::class)->count([]));
        $handedOverGroup = $this->entityManager()->find(Group::class, $group->getId());
        self::assertSame('carol', $handedOverGroup->getCreator()->getUsername());
        self::assertSame('owner', $this->entityManager()->getRepository(GroupMember::class)->findOneBy(['usergroup' => $handedOverGroup, 'user' => $handedOverGroup->getCreator()])->getRole());
        $this->client->request('GET', '/profil/settings/edit');
        self::assertResponseRedirects();
        $this->client->request('GET', '/profil/' . rawurlencode(User::DELETED_MEMBER_USERNAME));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/forum/thread/' . $anonymisedReply->getThread()->getSlug());
        self::assertSelectorTextContains('body', User::DELETED_MEMBER_USERNAME);
        self::assertSelectorTextNotContains('body', 'Banni');
    }

    public function testAdministratorDeletesAMemberButNotTheStaff(): void
    {
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $moderator = $this->createMember('modo', ['ROLE_MODERATOR']);
        $bob = $this->createMember('bob');
        $this->client->loginUser($moderator);
        $this->client->request('GET', '/admin/moderation/member/' . $bob->getId());
        self::assertSelectorTextNotContains('body', 'Supprimer définitivement');
        $this->client->loginUser($this->reload($admin));
        $crawler = $this->client->request('GET', '/admin/moderation/member/' . $moderator->getId());
        self::assertSelectorTextNotContains('body', 'Supprimer définitivement');
        $crawler = $this->client->request('GET', '/admin/moderation/member/' . $bob->getId());
        $form = $crawler->selectButton('Supprimer définitivement')->form(['confirm_username' => 'bo', 'reason' => 'Demande écrite du membre.']);
        $this->client->submit($form);
        self::assertNotNull($this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'bob']));
        $this->client->submit($form, ['confirm_username' => 'bob']);
        self::assertResponseRedirects();
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), 'Demande écrite du membre.');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'bob']));
    }

    public function testInactiveAccountIsWarnedThenDeletedUnlessTheMemberComesBack(): void
    {
        $sleeper = $this->createMember('sleeper');
        $returning = $this->createMember('returning');
        $moderator = $this->createMember('modo', ['ROLE_MODERATOR']);
        $active = $this->createMember('active');
        foreach ([$sleeper, $returning, $moderator] as $member) {
            $member->setLastActivityAt(new \DateTimeImmutable('-3 years +10 days'));
        }
        $active->setLastActivityAt(new \DateTimeImmutable('-1 day'));
        $this->entityManager()->flush();
        $purger = static::getContainer()->get(InactiveAccountPurger::class);
        self::assertSame(['warned' => 2, 'deleted' => 0], $purger->purge());
        self::assertEmailCount(2);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), 'supprimé le');
        self::assertSame(['warned' => 0, 'deleted' => 0], $purger->purge());
        $this->client->loginUser($this->reload($returning));
        $this->client->request('POST', '/heartbeat');
        self::assertNull($this->reload($returning)->getInactivityWarnedAt());
        self::assertSame(['warned' => 0, 'deleted' => 1], $purger->purge(new \DateTimeImmutable('+31 days')));
        $this->entityManager()->clear();
        $remaining = array_map(static fn (User $member): string => $member->getUsername(), $this->entityManager()->getRepository(User::class)->findAll());
        self::assertNotContains('sleeper', $remaining);
        self::assertContains('returning', $remaining);
        self::assertContains('modo', $remaining);
    }

    public function testClosedReportsAreKeptWhileTheSanctionLasts(): void
    {
        $reporter = $this->createMember('alice');
        $moderator = $this->createMember('modo', ['ROLE_MODERATOR']);
        $forgiven = $this->createMember('bob');
        $banned = $this->createMember('carol');
        $banned->suspend(null, 'Harcèlement');
        $reports = [];
        foreach ([$forgiven, $banned, null] as $targetAuthor) {
            $report = new Report($reporter, ReportTargetType::Post, 1, $targetAuthor, ReportReason::Spam, null, 'Extrait', null);
            $report->close([ReportResolution::Dismissed], $moderator, null);
            $this->entityManager()->persist($report);
            $reports[] = $report;
        }
        $this->entityManager()->persist(new Report($reporter, ReportTargetType::Post, 2, $forgiven, ReportReason::Spam, null, 'En attente', null));
        $this->entityManager()->flush();
        $this->entityManager()->getConnection()->executeStatement('UPDATE report SET handled_at = :old WHERE handled_at IS NOT NULL', ['old' => (new \DateTimeImmutable('-13 months'))->format('Y-m-d H:i:s')]);
        self::assertSame(['reports' => 2, 'appeals' => 0], static::getContainer()->get(ModerationRecordPurger::class)->purge());
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Report::class, $reports[1]->getId()));
        self::assertSame(2, $this->entityManager()->getRepository(Report::class)->count([]));
    }
}
