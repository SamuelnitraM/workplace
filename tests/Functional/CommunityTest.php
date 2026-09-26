<?php

namespace App\Tests\Functional;

use App\Entity\Friendship;
use App\Entity\GalleryPhoto;
use App\Entity\MemberDailyActivity;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Entity\UserBlock;

class CommunityTest extends FunctionalTestCase
{
    public function testFriendSuggestionsComeFromMutualFriends(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        $dave = $this->createMember('dave');
        $blocked = $this->createMember('eve');
        $this->befriend($alice, $bob);
        $this->befriend($alice, $carol);
        $this->befriend($bob, $dave);
        $this->befriend($carol, $dave);
        $this->befriend($bob, $blocked);
        $this->entityManager()->persist(new UserBlock($alice, $blocked));
        $this->entityManager()->flush();
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/friendship/list');
        $suggestions = $crawler->filter('#suggestions-title')->closest('aside')->filter('li');
        self::assertCount(1, $suggestions);
        self::assertStringContainsString('dave', $suggestions->text());
        self::assertStringContainsString('2 amis en commun', $suggestions->text());
    }

    public function testFriendListOfAnotherMemberIsPublicExceptBetweenBlockedMembers(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        $this->befriend($alice, $bob);
        $this->befriend($alice, $carol);
        $this->befriend($carol, $bob);
        $this->client->loginUser($bob);
        $this->client->request('GET', '/profil/alice/amis');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'carol');
        self::assertSelectorTextContains('main', 'Ami en commun');
        $this->entityManager()->persist(new UserBlock($this->reload($alice), $this->entityManager()->find(User::class, $bob->getId())));
        $this->entityManager()->flush();
        $this->client->request('GET', '/profil/alice/amis');
        self::assertResponseStatusCodeSame(404);
    }

    public function testGalleryPhotoVisibilityTogglesWithoutReloading(): void
    {
        $alice = $this->createMember('alice');
        $photo = (new GalleryPhoto())->setFilename('test-alice.webp')->setOwner($alice);
        $this->entityManager()->persist($photo);
        $this->entityManager()->flush();
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/profil/alice');
        $form = $crawler->filter('form[action="/profil/alice/gallery/' . $photo->getId() . '/visibility"]')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($answer['visible']);
        self::assertStringContainsString('is-hidden-photo', $answer['tile']);
        self::assertStringContainsString('data-photo-id="' . $photo->getId() . '"', $answer['tile']);
        $this->entityManager()->clear();
        self::assertFalse($this->entityManager()->find(GalleryPhoto::class, $photo->getId())->isVisible());
    }

    public function testPrivateMessageMentionsLinkToProfilesAndStayEscaped(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $this->befriend($alice, $bob);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/messages/bob');
        $token = $crawler->filter('#message-form input[name="_token"]')->attr('value');
        $this->client->xmlHttpRequest('POST', '/messages/bob/send', ['_token' => $token, 'content' => 'Salut @bob et @personne <b>gras</b>']);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Salut <a href="/profil/bob" class="mention">@bob</a> et @personne &lt;b&gt;gras&lt;/b&gt;', $answer['contentHtml']);
        self::assertSame(1, $this->entityManager()->getRepository(PrivateMessage::class)->count([]));
        $this->client->request('GET', '/messages/bob');
        self::assertSelectorExists('.chat-bubble a.mention[href="/profil/bob"]');
    }

    public function testFirstHeartbeatOfTheDayRecordsTheDailyActivity(): void
    {
        $alice = $this->createMember('alice');
        $this->client->loginUser($alice);
        $this->client->request('POST', '/heartbeat');
        self::assertResponseStatusCodeSame(204);
        $activities = $this->entityManager()->getRepository(MemberDailyActivity::class)->findAll();
        self::assertCount(1, $activities);
        self::assertSame((new \DateTimeImmutable())->format('Y-m-d'), $activities[0]->getDay()->format('Y-m-d'));
    }

    public function testBannerIsStoredAsTheChosenFrame(): void
    {
        $alice = $this->createMember('alice');
        $source = sys_get_temp_dir() . '/banner-source-' . uniqid() . '.png';
        $image = imagecreatetruecolor(1000, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 20, 200));
        imagefilledrectangle($image, 0, 500, 999, 699, imagecolorallocate($image, 220, 20, 20));
        imagepng($image, $source);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/profil/settings/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['user_profile_form[coverFile]']->upload($source);
        $values = $form->getPhpValues();
        $values['cover_crop'] = '0,500,800,200';
        $this->client->request('POST', $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseRedirects('/profil/alice');
        $cover = $this->reload($alice)->getCover();
        $storedPath = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/covers/' . $cover;
        [$width, $height] = getimagesize($storedPath);
        $stored = imagecreatefromwebp($storedPath);
        $centre = imagecolorsforindex($stored, imagecolorat($stored, intdiv($width, 2), intdiv($height, 2)));
        unlink($storedPath);
        unlink($source);
        self::assertSame($height * 4, $width);
        self::assertGreaterThan(150, $centre['red']);
        self::assertLessThan(80, $centre['blue']);
    }

    public function testMentionSuggestionsListMatchingMembers(): void
    {
        $this->createMember('alice');
        $this->createMember('albert');
        $this->client->loginUser($this->createMember('bob'));
        $this->client->request('GET', '/mentions?q=al');
        $usernames = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['users'], 'username');
        sort($usernames);
        self::assertSame(['albert', 'alice'], $usernames);
    }

    private function befriend(User $requester, User $receiver): void
    {
        $friendship = (new Friendship())->setRequester($requester)->setReceiver($receiver)->setStatus('accepted');
        $this->entityManager()->persist($friendship);
        $this->entityManager()->flush();
    }
}
