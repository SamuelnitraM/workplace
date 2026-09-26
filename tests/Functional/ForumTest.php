<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Thread;
use App\Entity\User;

class ForumTest extends FunctionalTestCase
{
    public function testReadOnlyCategoryIsReservedToAdministrators(): void
    {
        $member = $this->createMember('alice');
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $news = $this->createCategory('Annonces', 'annonces', readOnly: true);
        $thread = $this->createThread($news, $admin, 'Version 1.4 : nouveautés');
        $this->client->loginUser($member);
        $this->client->request('GET', '/forum/category/annonces/new-thread');
        self::assertResponseRedirects('/forum/category/annonces');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-error', 'lecture seule');
        self::assertSelectorNotExists('a[href="/forum/category/annonces/new-thread"]');
        $this->client->request('GET', '/forum/thread/' . $thread->getSlug());
        self::assertSelectorNotExists('#reply');
        $this->client->request('POST', '/forum/thread/' . $thread->getSlug(), ['post_form' => ['content' => 'Réponse interdite']]);
        self::assertSame(1, $this->entityManager()->getRepository(Post::class)->count([]));
        $this->client->loginUser($this->reload($admin));
        $this->client->request('GET', '/forum/category/annonces/new-thread');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/forum/thread/' . $thread->getSlug());
        self::assertSelectorExists('#reply');
    }

    public function testNewsFilterShowsTheThreadsOfReadOnlyCategories(): void
    {
        $member = $this->createMember('alice');
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $this->createThread($this->createCategory('Annonces', 'annonces', readOnly: true), $admin, 'Version 1.4 : nouveautés');
        $this->createThread($this->createCategory('Peinture', 'peinture'), $admin, 'Sujet ordinaire');
        $this->client->loginUser($member);
        $this->client->request('GET', '/?fil=actualites');
        self::assertSelectorTextContains('#feed', 'Version 1.4 : nouveautés');
        self::assertSelectorTextContains('#feed', 'a publié une actualité');
        self::assertSelectorTextNotContains('#feed', 'Sujet ordinaire');
    }

    public function testCategoryWithThreadsListsItsSubcategoriesInASideColumn(): void
    {
        $member = $this->createMember('alice');
        $parent = $this->createCategory('Space Marines', 'space-marines');
        $child = $this->createCategory('Blood Angels', 'blood-angels');
        $child->setParent($parent);
        $grouping = $this->createCategory('Warhammer', 'warhammer')->setAllowThreads(false);
        $parent->setParent($grouping);
        $this->entityManager()->flush();
        $this->client->loginUser($member);
        $this->client->request('GET', '/forum/category/space-marines');
        self::assertSelectorTextContains('.forum-subcategory-nav', 'Blood Angels');
        $this->client->request('GET', '/forum/category/warhammer');
        self::assertSelectorNotExists('.forum-subcategory-nav');
        self::assertSelectorTextContains('main .card', 'Space Marines');
        self::assertSelectorTextNotContains('main', 'Sous-catégories');
    }

    public function testSiteSearchFindsForumSectionsAndThreads(): void
    {
        $author = $this->createMember('alice');
        $category = $this->createCategory('Peinture et effets', 'peinture-et-effets');
        $this->createThread($category, $author, 'Effets de peinture OSL');
        $this->client->request('GET', '/search/api?q=peinture');
        $results = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Peinture et effets', $results['categories'][0]['name']);
        self::assertSame('/forum/category/peinture-et-effets', $results['categories'][0]['url']);
        self::assertSame('Effets de peinture OSL', $results['threads'][0]['title']);
        self::assertSame('Peinture et effets', $results['threads'][0]['context']);
    }

    private function createCategory(string $name, string $slug, bool $readOnly = false): Category
    {
        $category = (new Category())->setName($name)->setSlug($slug)->setPosition(1)->setReadOnly($readOnly);
        $this->entityManager()->persist($category);
        $this->entityManager()->flush();
        return $category;
    }

    private function createThread(Category $category, User $author, string $title): Thread
    {
        $thread = (new Thread())->setTitle($title)->setSlug('sujet-' . uniqid())->setCategory($category)->setAuthor($author);
        $post = (new Post())->setContent('Contenu du sujet')->setAuthor($author)->setThread($thread)->setIsFirst(true);
        $this->entityManager()->persist($thread);
        $this->entityManager()->persist($post);
        $this->entityManager()->flush();
        return $thread;
    }
}
