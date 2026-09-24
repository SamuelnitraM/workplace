<?php

namespace App\Tests\Functional;

use App\Entity\Friendship;
use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\PrivateConversation;
use App\Entity\PrivateMessage;
use App\Entity\Report;
use App\Moderation\ReportResolution;

class ModerationTest extends FunctionalTestCase
{
    public function testBlockingEndsFriendshipAndPreventsContact(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $friendship = (new Friendship())->setRequester($alice)->setReceiver($bob)->setStatus('accepted');
        $this->entityManager()->persist($friendship);
        $this->entityManager()->flush();
        $this->client->loginUser($bob);
        $crawler = $this->client->request('GET', '/profil/alice');
        $this->client->submit($crawler->selectButton('Bloquer')->form());
        self::assertResponseRedirects('/profil/alice');
        self::assertSame(0, $this->entityManager()->getRepository(Friendship::class)->count([]));
        $this->client->loginUser($this->reload($alice));
        $crawler = $this->client->request('GET', '/profil/bob');
        self::assertSelectorTextContains('.profile-actions', 'Contact impossible');
        self::assertSelectorNotExists('form[action="/friendship/request/bob"]');
        $friendshipToken = $crawler->filter('form[action="/friendship/block/bob"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/friendship/request/bob', ['_token' => $friendshipToken]);
        $this->client->followRedirect();
        self::assertSame(0, $this->entityManager()->getRepository(Friendship::class)->count([]));
    }

    public function testReportedPostCanBeHiddenByAnAdministrator(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $reply = $this->createThreadWithReply($alice, $bob);
        $threadSlug = $reply->getThread()->getSlug();
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/signaler/reponse/' . $reply->getId());
        $this->client->submit($crawler->selectButton('Envoyer le signalement')->form([
            'report_form[reason]' => 'harassment',
            'report_form[details]' => 'Insulte gratuite',
        ]));
        self::assertResponseRedirects();
        $report = $this->entityManager()->getRepository(Report::class)->findOneBy([]);
        self::assertTrue($report->isPending());
        self::assertSame($bob->getId(), $report->getTargetAuthor()->getId());
        $this->client->loginUser($this->reload($admin));
        $crawler = $this->client->request('GET', '/admin/moderation/report/' . $report->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Masquer')->form());
        self::assertResponseRedirects();
        $this->entityManager()->clear();
        self::assertSame(ReportResolution::Hidden, $this->entityManager()->find(Report::class, $report->getId())->getResolution());
        self::assertTrue($this->entityManager()->find(Post::class, $reply->getId())->isHiddenByModeration());
        self::assertSame(1, $this->entityManager()->getRepository(Notification::class)->count(['type' => Notification::TYPE_MODERATION_NOTICE]));
        self::assertEmailCount(1);
        $this->client->loginUser($this->reload($alice));
        $this->client->request('GET', '/forum/thread/' . $threadSlug);
        self::assertSelectorTextContains('#post-' . $reply->getId(), 'masqué par la modération');
        self::assertSelectorTextNotContains('#post-' . $reply->getId(), 'Contenu insultant');
    }

    public function testMembersCannotReportTheirOwnContent(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $reply = $this->createThreadWithReply($alice, $bob);
        $this->client->loginUser($this->reload($bob));
        $this->client->request('GET', '/signaler/reponse/' . $reply->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuspendedMemberIsLoggedOutAndCannotLogIn(): void
    {
        $bob = $this->createMember('bob');
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/moderation/member/' . $bob->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Suspendre')->form([
            'duration' => 'P7D',
            'reason' => 'Insultes répétées',
        ]));
        self::assertResponseRedirects();
        self::assertTrue($this->reload($bob)->isSuspended());
        self::assertEmailCount(1);
        $this->client->loginUser($this->reload($bob));
        $this->client->request('GET', '/forum/');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-error', 'Insultes répétées');
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'bob', '_password' => self::PASSWORD]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'suspendu');
    }

    public function testBackOfficePagesRender(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $bob->suspend(null, 'Spam');
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $reply = $this->createThreadWithReply($alice, $bob);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/signaler/reponse/' . $reply->getId());
        $this->client->submit($crawler->selectButton('Envoyer le signalement')->form(['report_form[reason]' => 'spam']));
        $this->client->loginUser($this->reload($admin));
        foreach (['/admin', '/admin/report', '/admin/user'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        self::assertSelectorTextContains('body', 'Suspendu définitivement');
        $this->client->request('GET', '/admin/report');
        self::assertSelectorTextContains('body', 'Spam ou publicité');
    }

    public function testPrivateMessageReportReturnsToTheConversation(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $conversation = (new PrivateConversation())->setParticipant1($bob)->setParticipant2($alice);
        $message = (new PrivateMessage())->setContent('Achète mes figurines volées')->setAuthor($bob);
        $conversation->addMessage($message);
        $this->entityManager()->persist($conversation);
        $this->entityManager()->persist($message);
        $this->entityManager()->flush();
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/messages/bob');
        self::assertCount(1, $crawler->filter('a[href="/signaler/message/' . $message->getId() . '"]'));
        $crawler = $this->client->click($crawler->filter('a[href="/signaler/message/' . $message->getId() . '"]')->link());
        $this->client->submit($crawler->selectButton('Envoyer le signalement')->form(['report_form[reason]' => 'scam']));
        self::assertResponseRedirects('/messages/bob');
        $report = $this->entityManager()->getRepository(Report::class)->findOneBy([]);
        self::assertNull($report->getTargetUrl());
        self::assertStringContainsString('figurines volées', $report->getExcerpt());
    }

    public function testModeratorOnlyReachesModerationPages(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $moderator = $this->createMember('modo', ['ROLE_MODERATOR']);
        $reply = $this->createThreadWithReply($alice, $bob);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/signaler/reponse/' . $reply->getId());
        $this->client->submit($crawler->selectButton('Envoyer le signalement')->form(['report_form[reason]' => 'spam']));
        $this->client->loginUser($this->reload($moderator));
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'File de modération');
        self::assertSelectorTextNotContains('.main-sidebar, body', 'Utilisateurs');
        foreach (['/admin/user', '/admin/thread', '/admin/category'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $report = $this->entityManager()->getRepository(Report::class)->findOneBy([]);
        $crawler = $this->client->request('GET', '/admin/moderation/report/' . $report->getId());
        $this->client->submit($crawler->selectButton('Masquer')->form());
        self::assertResponseRedirects();
        $this->entityManager()->clear();
        self::assertTrue($this->entityManager()->find(Post::class, $reply->getId())->isHiddenByModeration());
        $this->client->request('GET', '/forum/thread/' . $reply->getThread()->getSlug());
        self::assertSelectorTextContains('#post-' . $reply->getId(), 'Contenu insultant');
    }

    public function testModeratorCannotSanctionTheTeam(): void
    {
        $moderator = $this->createMember('modo', ['ROLE_MODERATOR']);
        $otherModerator = $this->createMember('modo2', ['ROLE_MODERATOR']);
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $this->client->loginUser($moderator);
        foreach ([$otherModerator, $admin] as $staffMember) {
            $this->client->request('GET', '/admin/moderation/member/' . $staffMember->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('button[value=suspend]');
        }
        $this->client->loginUser($this->reload($admin));
        $crawler = $this->client->request('GET', '/admin/moderation/member/' . $otherModerator->getId());
        $this->client->submit($crawler->selectButton('Suspendre')->form(['duration' => 'P1D', 'reason' => 'Abus de pouvoir']));
        self::assertTrue($this->reload($otherModerator)->isSuspended());
    }
}
