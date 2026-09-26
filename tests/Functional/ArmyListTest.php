<?php

namespace App\Tests\Functional;

use App\Army\BattleSize;
use App\Entity\ArmyList;
use App\Entity\FactionDetachement;
use App\Entity\FactionEnhancement;
use App\Entity\FactionUnit;

class ArmyListTest extends FunctionalTestCase
{
    /** @var array<string, FactionUnit> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue = [
            'hero' => $this->factionUnit('Marneus Calgar', 200, ['Epic Hero', 'Character', 'Infantry']),
            'captain' => $this->factionUnit('Captain', 80, ['Character', 'Infantry']),
            'squad' => $this->factionUnit('Intercessor Squad', 80, ['Battleline', 'Infantry'], [['models' => 5, 'points' => 80], ['models' => 10, 'points' => 160]]),
            'tank' => $this->factionUnit('Predator', 130, ['Vehicle']),
        ];
        $detachment = (new FactionDetachement())->setBsdataId('det-1')->setName('Gladius Task Force')->setFaction('Ultramarines')->setSourceFile('test.json');
        $enhancement = (new FactionEnhancement('enh-1', 'Ultramarines', 'Gladius Task Force'))->setName('Artificer Armour')->setPoints(20);
        $this->entityManager()->persist($detachment);
        $this->entityManager()->persist($enhancement);
        $this->entityManager()->flush();
    }

    public function testOfficialListRefusesASecondEpicHero(): void
    {
        $this->client->loginUser($this->createMember('alice'));
        $this->submitNewList(BattleSize::StrikeForce, [
            ['factionUnitId' => $this->catalogue['hero']->getId(), 'quantity' => 1, 'warlord' => true],
            ['factionUnitId' => $this->catalogue['hero']->getId(), 'quantity' => 1],
        ]);
        self::assertResponseRedirects('/army/new');
        self::assertSame(0, $this->entityManager()->getRepository(ArmyList::class)->count([]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-error', 'personnage épique');
    }

    public function testOfficialListRefusesTheWrongWarlordAndExcessPoints(): void
    {
        $this->client->loginUser($this->createMember('alice'));
        $this->submitNewList(BattleSize::Incursion, [
            ['factionUnitId' => $this->catalogue['tank']->getId(), 'quantity' => 1, 'warlord' => true],
            ...array_fill(0, 7, ['factionUnitId' => $this->catalogue['tank']->getId(), 'quantity' => 1]),
        ]);
        $this->client->followRedirect();
        $error = $this->client->getCrawler()->filter('.toast-error')->text();
        self::assertStringContainsString('limité à 1000 pts', $error);
        self::assertStringContainsString('3 autorisés au maximum', $error);
        self::assertStringContainsString('ne peut pas être Seigneur de guerre', $error);
    }

    public function testFreeListAcceptsWhatAnOfficialListRefuses(): void
    {
        $this->client->loginUser($this->createMember('alice'));
        $this->submitNewList(null, [
            ['factionUnitId' => $this->catalogue['hero']->getId(), 'quantity' => 2],
            ['factionUnitId' => $this->catalogue['tank']->getId(), 'quantity' => 9],
        ]);
        self::assertResponseRedirects();
        $list = $this->entityManager()->getRepository(ArmyList::class)->findOneBy([]);
        self::assertFalse($list->isOfficial());
        self::assertSame(400 + 9 * 130, $list->getTotalPoints());
    }

    public function testConformListExportsAndImportsBackIdentically(): void
    {
        $alice = $this->createMember('alice');
        $this->client->loginUser($alice);
        $this->submitNewList(BattleSize::StrikeForce, [
            ['factionUnitId' => $this->catalogue['hero']->getId(), 'quantity' => 1, 'warlord' => true],
            ['factionUnitId' => $this->catalogue['captain']->getId(), 'quantity' => 1, 'enhancement' => 'Artificer Armour'],
            ['factionUnitId' => $this->catalogue['squad']->getId(), 'quantity' => 1, 'modelCount' => 10],
        ]);
        $list = $this->entityManager()->getRepository(ArmyList::class)->findOneBy([]);
        self::assertSame(200 + 100 + 160, $list->getTotalPoints());
        $this->client->request('GET', '/army/' . $list->getId());
        self::assertSelectorTextContains('.alert-success', 'Liste conforme');
        $this->client->request('GET', '/army/' . $list->getId() . '/export.txt');
        $text = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("Strike Force (2000 Points)\nGladius Task Force", $text);
        self::assertStringContainsString("Captain (100 Points)\n  • Enhancements: Artificer Armour", $text);
        self::assertStringContainsString('Intercessor Squad (160 Points)', $text);
        $crawler = $this->client->request('GET', '/army/import');
        $this->client->submit($crawler->selectButton('Importer')->form(['text' => $text]));
        self::assertResponseRedirects();
        $imported = $this->entityManager()->getRepository(ArmyList::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotSame($list->getId(), $imported->getId());
        self::assertSame(BattleSize::StrikeForce, $imported->getBattleSize());
        self::assertSame('Gladius Task Force', $imported->getDetachment());
        self::assertSame(460, $imported->getTotalPoints());
        $units = [];
        foreach ($imported->getUnits() as $unit) {
            $units[$unit->getName()] = $unit;
        }
        self::assertTrue($units['Marneus Calgar']->isWarlord());
        self::assertSame('Artificer Armour', $units['Captain']->getEnhancementName());
        self::assertSame(10, $units['Intercessor Squad']->getModelCount());
    }

    public function testPublicListIsExploredAndDuplicatedByAnotherMember(): void
    {
        $alice = $this->createMember('alice');
        $this->client->loginUser($alice);
        $this->submitNewList(null, [['factionUnitId' => $this->catalogue['captain']->getId(), 'quantity' => 1]], true);
        $list = $this->entityManager()->getRepository(ArmyList::class)->findOneBy([]);
        $this->client->loginUser($this->reload($this->createMember('bob')));
        $crawler = $this->client->request('GET', '/army/explorer?faction=Ultramarines');
        self::assertSelectorTextContains('main', 'Liste de test');
        $crawler = $this->client->request('GET', '/army/' . $list->getId());
        $this->client->submit($crawler->selectButton('Dupliquer dans mes listes')->form());
        self::assertResponseRedirects();
        $copies = $this->entityManager()->getRepository(ArmyList::class)->findBy(['name' => 'Copie de Liste de test']);
        self::assertCount(1, $copies);
        self::assertSame('bob', $copies[0]->getOwner()->getUsername());
        self::assertFalse($copies[0]->isPublic());
        self::assertCount(1, $copies[0]->getUnits());
    }

    public function testAudienceCountersIgnoreTheOwnerAndCountViewsOncePerSession(): void
    {
        $alice = $this->createMember('alice');
        $this->client->loginUser($alice);
        $this->submitNewList(null, [['factionUnitId' => $this->catalogue['captain']->getId(), 'quantity' => 1]], true);
        $list = $this->entityManager()->getRepository(ArmyList::class)->findOneBy([]);
        $listUrl = '/army/' . $list->getId();
        $this->client->request('GET', $listUrl);
        $this->client->request('GET', $listUrl . '/export.txt');
        $this->client->loginUser($this->reload($this->createMember('bob')));
        $this->client->request('GET', $listUrl);
        $crawler = $this->client->request('GET', $listUrl);
        $this->client->request('GET', $listUrl . '/export.txt');
        $this->client->request('GET', $listUrl . '/imprimer');
        $this->client->submit($crawler->selectButton('Dupliquer dans mes listes')->form());
        $this->entityManager()->clear();
        $list = $this->entityManager()->find(ArmyList::class, $list->getId());
        self::assertSame(1, $list->getViewCount());
        self::assertSame(1, $list->getExportCount());
        self::assertSame(1, $list->getDuplicationCount());
        $this->client->request('GET', '/army/explorer');
        self::assertSelectorTextContains('[aria-labelledby="most-duplicated-title"]', 'Liste de test');
        self::assertSelectorTextContains('[aria-labelledby="most-exported-title"]', 'Liste de test');
    }

    public function testDatasheetFragmentShowsTheProfile(): void
    {
        $this->client->request('GET', '/army/fiche?unit=' . $this->catalogue['squad']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.datasheet-name', 'Intercessor Squad');
        self::assertSelectorTextContains('.datasheet-table', 'Bolt rifle');
    }

    /** @param list<array<string, mixed>> $units */
    private function submitNewList(?BattleSize $battleSize, array $units, bool $public = false): void
    {
        $this->client->request('GET', '/army/new');
        $this->client->request('POST', '/army/new', [
            '_token' => $this->builderCsrfToken((string) $this->client->getResponse()->getContent()),
            'name' => 'Liste de test',
            'faction' => 'Ultramarines',
            'detachment' => 'Gladius Task Force',
            'battleSize' => $battleSize?->value ?? '',
            'isPublic' => $public ? '1' : '0',
            'units_json' => json_encode($units),
        ]);
    }

    /** The builder form lives in a <template>: its token is read from the raw HTML, after the template marker. */
    private function builderCsrfToken(string $html): string
    {
        $template = substr($html, (int) strpos($html, 'data-army-form-target="template"'));
        preg_match('/name="_token" value="([^"]+)"/', $template, $matches);
        return $matches[1];
    }

    /**
     * @param list<string> $keywords
     * @param list<array{models: int, points: int}> $pointsOptions
     */
    private function factionUnit(string $name, int $points, array $keywords, array $pointsOptions = []): FactionUnit
    {
        $unit = (new FactionUnit())
            ->setBsdataId(md5($name))
            ->setName($name)
            ->setFaction('Ultramarines')
            ->setCategory($keywords[0])
            ->setPoints($points)
            ->setSourceFile('test.json')
            ->setStatsData([
                'keywords' => $keywords,
                'models' => [['name' => $name, 'stats' => ['M' => '6"', 'T' => '4', 'Sv' => '3+', 'W' => '2', 'LD' => '6+', 'OC' => '2'], 'weapons' => [
                    ['name' => 'Bolt rifle', 'weaponType' => 'Ranged Weapons', 'Range' => '24"', 'A' => '2', 'BS' => '3+', 'S' => '4', 'AP' => '-1', 'D' => '1', 'Keywords' => 'Assault, Heavy'],
                ]]],
                'pointsOptions' => $pointsOptions ?: [['models' => 1, 'points' => $points]],
            ]);
        $this->entityManager()->persist($unit);
        $this->entityManager()->flush();
        return $unit;
    }
}
