<?php

namespace App\Tests\Functional;

use App\Entity\Appeal;
use App\Entity\ArmyList;
use App\Entity\Friendship;
use App\Entity\GalleryPhoto;
use App\Entity\User;
use App\Moderation\ModerationService;
use App\Moderation\SuspensionDuration;

class BannedMemberTest extends FunctionalTestCase
{
    public function testBannedMemberAppealsFromTheLoginPageAndTheModerationLiftsTheBan(): void
    {
        $bob = $this->createMember('bob');
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $this->ban($bob, 'Spam répété');
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['_username' => 'bob', '_password' => self::PASSWORD]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#suspension-notice', 'Spam répété');
        $appealUrl = $crawler->filter('#suspension-notice a')->attr('href');
        $this->client->request('GET', '/reclamation/' . $bob->getId());
        self::assertResponseRedirects('/login');
        $crawler = $this->client->request('GET', $appealUrl);
        self::assertResponseIsSuccessful();
        $crawler = $this->client->submit($crawler->selectButton('Envoyer la réclamation')->form(['message' => 'Trop court']));
        self::assertSelectorExists('.field-error');
        $this->client->submit($crawler->selectButton('Envoyer la réclamation')->form(['message' => 'Je n\'ai jamais envoyé ces messages, mon compte a été piraté.']));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#suspension-notice', 'en attente');
        self::assertSelectorNotExists('#suspension-notice a');
        $appeal = $this->entityManager()->getRepository(Appeal::class)->findOneBy([]);
        self::assertSame('Spam répété', $appeal->getSuspensionReason());
        self::assertTrue($appeal->isAgainstPermanentBan());
        $this->client->loginUser($this->reload($admin));
        $this->client->request('GET', '/admin/appeal');
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/admin/moderation/appeal/' . $appeal->getId());
        self::assertSelectorTextContains('body', 'mon compte a été piraté');
        self::assertSelectorTextContains('.sanction-ban', 'Banni définitivement');
        $this->client->submit($crawler->selectButton('Lever la sanction')->form(['response' => 'Après vérification, la sanction est levée.']));
        self::assertResponseRedirects();
        self::assertFalse($this->reload($bob)->isSuspended());
        self::assertSame(Appeal::STATUS_LIFTED, $this->entityManager()->find(Appeal::class, $appeal->getId())->getStatus());
        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), 'la sanction est levée');
    }

    public function testBannedMemberIsHiddenButForumPostsStayMarked(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $moderator = $this->createMember('modo', ['ROLE_MODERATOR']);
        $this->entityManager()->persist((new Friendship())->setRequester($alice)->setReceiver($bob)->setStatus('accepted'));
        $photo = (new GalleryPhoto())->setFilename('test-bob.webp')->setOwner($bob);
        $armyList = (new ArmyList())->setName('Liste de bob')->setFaction('Ultramarines')->setOwner($bob)->setIsPublic(true)->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager()->persist($photo);
        $this->entityManager()->persist($armyList);
        $this->entityManager()->flush();
        $reply = $this->createThreadWithReply($alice, $bob);
        $bob->setExperience(5000);
        $this->entityManager()->flush();
        $this->ban($bob, 'Harcèlement');
        $this->client->loginUser($this->reload($alice));
        $this->client->request('GET', '/profil/bob');
        self::assertSelectorTextContains('.user-title-banned', 'Banni');
        self::assertSelectorNotExists('#panel-gallery');
        $this->client->request('GET', '/profil/bob/photo/' . $photo->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/army/' . $armyList->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/profil/bob/amis');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/classement');
        self::assertSelectorTextNotContains('main', 'bob');
        $this->client->request('GET', '/mentions?q=bo');
        self::assertSame(['users' => []], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('GET', '/?fil=amis');
        self::assertSelectorNotExists('a[href="/profil/bob/photo/' . $photo->getId() . '"]');
        $this->client->request('GET', '/forum/thread/' . $reply->getThread()->getSlug());
        self::assertSelectorTextContains('#post-' . $reply->getId(), 'Contenu insultant');
        self::assertSelectorTextContains('#post-' . $reply->getId(), 'Banni');
        $this->client->loginUser($this->reload($moderator));
        $this->client->request('GET', '/profil/bob');
        self::assertSelectorExists('#panel-gallery');
        static::getContainer()->get(ModerationService::class)->liftSuspension($this->reload($bob));
        $this->client->loginUser($this->reload($alice));
        $this->client->request('GET', '/profil/bob/photo/' . $photo->getId());
        self::assertResponseIsSuccessful();
    }

    public function testOldThreadWarnsBeforeReplying(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $reply = $this->createThreadWithReply($alice, $bob);
        $thread = $reply->getThread();
        $this->client->loginUser($alice);
        $this->client->request('GET', '/forum/thread/' . $thread->getSlug());
        self::assertSelectorNotExists('#reply .alert-info');
        $thread->setUpdatedAt(new \DateTimeImmutable('-7 months'));
        $this->entityManager()->flush();
        $this->client->request('GET', '/forum/thread/' . $thread->getSlug());
        self::assertSelectorTextContains('#reply .alert-info', 'inactif depuis plus de 6 mois');
        self::assertSelectorExists('#reply form');
    }

    private function ban(User $member, string $reason): void
    {
        static::getContainer()->get(ModerationService::class)->suspend($this->reload($member), SuspensionDuration::Permanent, $reason);
    }
}
