<?php

namespace App\Tests\Functional;

use App\Entity\ArmyList;

class BackLinkTest extends FunctionalTestCase
{
    public function testBackLinkPointsToThePreviousPageOfTheSite(): void
    {
        $alice = $this->createMember('alice');
        $armyList = (new ArmyList())->setName('Liste')->setFaction('Ultramarines')->setOwner($alice)->setIsPublic(true)->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager()->persist($armyList);
        $this->entityManager()->flush();
        $this->client->loginUser($alice);
        $printUrl = '/army/' . $armyList->getId() . '/imprimer';
        $cases = [
            'http://localhost/army/explorer?faction=x' => ['/army/explorer?faction=x', 'Retour à l’explorateur'],
            'http://localhost/classement' => ['/classement', 'Retour au classement'],
            'https://ailleurs.example/army/' => ['/army/' . $armyList->getId(), 'Retour à la liste'],
            'http://localhost' . $printUrl => ['/army/' . $armyList->getId(), 'Retour à la liste'],
            null => ['/army/' . $armyList->getId(), 'Retour à la liste'],
        ];
        foreach ($cases as $referer => [$expectedUrl, $expectedLabel]) {
            $crawler = $this->client->request('GET', $printUrl, [], [], $referer ? ['HTTP_REFERER' => $referer] : []);
            $backLink = $crawler->filter('.print-toolbar a')->first();
            self::assertSame($expectedUrl, $backLink->attr('href'), (string) $referer);
            self::assertSame($expectedLabel, trim($backLink->text()), (string) $referer);
        }
    }
}
