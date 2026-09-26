<?php

namespace App\Tests\Functional;

use App\Entity\MemberDailyActivity;
use App\Statistics\DailyActivityHistory;
use App\Tour\TourCatalog;

class TourAndStatisticsTest extends FunctionalTestCase
{
    public function testTutorialListsEveryTourAndStartsItOnItsPage(): void
    {
        $this->client->request('GET', '/didacticiel');
        self::assertResponseRedirects('/login');
        $alice = $this->createMember('alice');
        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', '/didacticiel');
        self::assertResponseIsSuccessful();
        $launchLinks = $crawler->selectLink('Lancer la visite')->each(static fn ($link) => $link->attr('href'));
        self::assertCount(count(static::getContainer()->get(TourCatalog::class)->all()), $launchLinks);
        self::assertContains('/profil/alice?visite=galerie', $launchLinks);
        self::assertSelectorExists('footer a[href="/didacticiel"][data-tour="lost"]');
        $this->client->request('GET', '/forum/?visite=forum');
        $tourElement = $this->client->getCrawler()->filter('[data-controller="tour"]');
        self::assertCount(1, $tourElement);
        $steps = json_decode((string) $tourElement->attr('data-tour-steps-value'), true);
        self::assertSame('Le forum', $steps[0]['title']);
        self::assertSame('/groups/?visite=groupes', $tourElement->attr('data-tour-next-url-value'));
        $this->client->request('GET', '/forum/?visite=groupes');
        self::assertSelectorNotExists('[data-controller="tour"]');
        $this->client->request('GET', '/forum/?visite=inconnue');
        self::assertSelectorNotExists('[data-controller="tour"]');
    }

    public function testLastStepOfThePresentationPointsToTheTutorial(): void
    {
        $alice = $this->createMember('alice');
        $this->client->loginUser($alice);
        $this->client->request('GET', '/bienvenue/etape/4');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Si tu es perdu, lance le didacticiel');
        self::assertSelectorExists('main a[href="/didacticiel"]');
    }

    public function testDailyHistoryCountsActiveMembersRegistrationsAndRetention(): void
    {
        $alice = $this->createMember('alice');
        $bob = $this->createMember('bob');
        $today = new \DateTimeImmutable('today');
        $yesterday = $today->modify('-1 day');
        foreach ([[$alice, $yesterday], [$bob, $yesterday], [$alice, $today]] as [$member, $day]) {
            $this->entityManager()->persist(new MemberDailyActivity($member, $day));
        }
        $this->entityManager()->flush();
        $history = static::getContainer()->get(DailyActivityHistory::class)->lastDays($today);
        self::assertCount(DailyActivityHistory::DAYS, $history);
        $todayEntry = $history[DailyActivityHistory::DAYS - 1];
        self::assertSame($today->format('Y-m-d'), $todayEntry['date']->format('Y-m-d'));
        self::assertSame(1, $todayEntry['active']);
        self::assertSame(2, $todayEntry['registrations']);
        self::assertSame(50.0, $todayEntry['retention']);
        self::assertNull($history[DailyActivityHistory::DAYS - 2]['retention']);
    }

    public function testDashboardShowsChartsAndModerationSummary(): void
    {
        $admin = $this->createMember('admin', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '.hf-chart');
        self::assertSelectorCount(3 * DailyActivityHistory::DAYS, '.hf-chart-slot');
        self::assertSelectorTextContains('#moderation-stats-title', 'Modération');
        self::assertSelectorTextContains('body', 'Temps moyen de traitement');
    }
}
