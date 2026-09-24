<?php

namespace App\Tests\Functional;

use App\Entity\GalleryAlbum;
use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoLike;
use App\Entity\User;

class GalleryAlbumTest extends FunctionalTestCase
{
    public function testSelectedPhotosGoIntoANewAlbumThenBackToTheGallery(): void
    {
        $alice = $this->createMember('alice');
        [$first, $second, $third] = $this->createPhotos($alice, 3);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/profil/alice');
        $token = $crawler->filter('#gallery-album-create input[name=_token]')->attr('value');
        $this->client->request('POST', '/profil/alice/album/nouveau', ['_token' => $token, 'name' => 'Ultramarines', 'photo_ids' => [$first->getId(), $second->getId()]]);
        $album = $this->entityManager()->getRepository(GalleryAlbum::class)->findOneBy([]);
        self::assertResponseRedirects('/profil/alice/album/' . $album->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Ultramarines');
        self::assertCount(2, $this->client->getCrawler()->filter('.gallery-photo'));
        $this->client->request('POST', '/profil/alice/album/' . $album->getId() . '/ajouter', ['_token' => $token, 'photo_ids' => [$third->getId()]]);
        $this->entityManager()->clear();
        self::assertSame(3, $this->entityManager()->getRepository(GalleryPhoto::class)->count(['album' => $album->getId()]));
        $this->client->request('POST', '/profil/alice/album/' . $album->getId() . '/supprimer', ['_token' => $token]);
        self::assertResponseRedirects('/profil/alice');
        self::assertSame(0, $this->entityManager()->getRepository(GalleryAlbum::class)->count([]));
        self::assertSame(3, $this->entityManager()->getRepository(GalleryPhoto::class)->count(['album' => null]));
    }

    public function testPhotosOfAnotherMemberCannotBeMoved(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        [$bobPhoto] = $this->createPhotos($bob, 1);
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/profil/alice');
        $token = $crawler->filter('#gallery-album-create input[name=_token]')->attr('value');
        $this->client->request('POST', '/profil/alice/album/nouveau', ['_token' => $token, 'name' => 'Vol', 'photo_ids' => [$bobPhoto->getId()]]);
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(GalleryPhoto::class, $bobPhoto->getId())->getAlbum());
        $this->client->request('POST', '/profil/bob/album/nouveau', ['_token' => $token, 'name' => 'Intrusion']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPhotoPageShowsTheAlbumAndBrowsesItsPhotos(): void
    {
        $alice = $this->createMember('alice');
        [$older, $newer] = $this->createPhotos($alice, 2);
        $album = new GalleryAlbum($alice, 'Kill Team');
        $this->entityManager()->persist($album);
        $older->setAlbum($album);
        $newer->setAlbum($album);
        $this->entityManager()->flush();
        $this->client->request('GET', '/profil/alice/photo/' . $newer->getId());
        self::assertSelectorTextContains('nav[aria-label="Fil d\'Ariane"]', 'Album « Kill Team »');
        self::assertSelectorExists('a.photo-stage-nav-next[href="/profil/alice/photo/' . $older->getId() . '"]');
        self::assertSelectorNotExists('a.photo-stage-nav-previous');
        self::assertSelectorTextContains('main', '1 / 2 dans l\'album');
    }

    public function testWeeklyTrendsShowTheMostLikedPhotos(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $carol = $this->createMember('carol');
        [$plain, $popular] = $this->createPhotos($alice, 2);
        foreach ([$bob, $carol] as $fan) {
            $this->entityManager()->persist(new GalleryPhotoLike($popular, $fan));
        }
        $this->entityManager()->persist(new GalleryPhotoLike($plain, $bob));
        $this->entityManager()->flush();
        $crawler = $this->client->request('GET', '/');
        $trending = $crawler->filter('section[aria-labelledby="trending-title"] a');
        self::assertCount(2, $trending);
        self::assertSame('/profil/alice/photo/' . $popular->getId(), $trending->first()->attr('href'));
    }

    /** @return GalleryPhoto[] oldest first */
    private function createPhotos(User $owner, int $count): array
    {
        $photos = [];
        for ($index = 0; $index < $count; ++$index) {
            $photo = (new GalleryPhoto())->setFilename('test-' . $owner->getUsername() . '-' . $index . '.webp')->setOwner($owner);
            (new \ReflectionProperty(GalleryPhoto::class, 'createdAt'))->setValue($photo, new \DateTimeImmutable('-' . ($count - $index) . ' hours'));
            $this->entityManager()->persist($photo);
            $photos[] = $photo;
        }
        $this->entityManager()->flush();
        return $photos;
    }
}
