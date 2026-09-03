<?php

namespace App\Controller;

use App\Entity\ArmyList;
use App\Entity\ArmyUnit;
use App\Repository\ArmyListRepository;
use App\Repository\FactionDetachementRepository;
use App\Repository\FactionUnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/army', name: 'app_army_')]
class ArmyListController extends AbstractController
{
    // Liste des armées de l'utilisateur
    #[Route('/', name: 'index')]
    public function index(ArmyListRepository $armyListRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $lists = $armyListRepository->findBy(
            ['owner' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('army/index.html.twig', [
            'lists' => $lists,
        ]);
    }

    // Créer une nouvelle liste
    #[Route('/new', name: 'new')]
    public function new(Request $request,EntityManagerInterface $em): Response 
    {
        if ($request->isMethod('POST')) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            $armyList = new ArmyList();
            $armyList->setName($request->request->get('name'));
            $armyList->setFaction($request->request->get('faction'));
            $armyList->setDetachment($request->request->get('detachment') ?: null);
            $armyList->setDescription($request->request->get('description') ?: null);
            $armyList->setOwner($user);
            $armyList->setIsPublic($request->request->get('isPublic') === '1');

            $unitsJson = $request->request->get('units_json', '[]');
            $unitsData = json_decode($unitsJson, true) ?: [];

            $totalPoints = 0;
            foreach ($unitsData as $unitData) {
                $unit = new ArmyUnit();
                $unit->setName($unitData['name']);
                $unit->setQuantity((int) $unitData['quantity']);
                $unit->setCategory($unitData['category'] ?? null);
                $unit->setStatsData($unitData['statsData'] ?? null);
                $unit->setPoints((int) $unitData['points']);
                $armyList->addUnit($unit);

                $totalPoints += (int) $unitData['points'] * (int) $unitData['quantity'];
            }
            $armyList->setTotalPoints($totalPoints);

            $em->persist($armyList);
            $em->flush();

            $this->addFlash('success', 'Liste d\'armée créée avec succès !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }

        return $this->render('army/new.html.twig');
    }

    #[Route('/units/{faction}', name: 'units_by_faction', methods: ['GET'])]
    public function unitsByFaction(string $faction, FactionUnitRepository $factionUnitRepository): JsonResponse
    {
        $units = $factionUnitRepository->findBy(['faction' => $faction], ['name' => 'ASC']);

        return $this->json(array_map(fn($unit) => [
            'id' => $unit->getId(),
            'name' => $unit->getNameFr() ?: $unit->getName(),
            'nameEn' => $unit->getName(),
            'points' => $unit->getPoints(),
            'category' => $unit->getCategory(),
            'statsData' => $unit->getStatsData(),
        ], $units));
    }

    #[Route('/detachments/{faction}', name: 'detachments_by_faction', methods: ['GET'])]
    public function detachmentsByFaction(string $faction, FactionDetachementRepository $repo): JsonResponse
    {
        $detachments = $repo->findBy(['faction' => $faction], ['name' => 'ASC']);

        return $this->json(array_map(fn($d) => [
            'id' => $d->getId(),
            'name' => $d->getNameFr() ?: $d->getName(),
            'nameEn' => $d->getName(),
        ], $detachments));
    }

    // Voir une liste
    #[Route('/{id}', name: 'show')]
    public function show(int $id, ArmyListRepository $armyListRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $armyList = $armyListRepository->find($id);

        if (!$armyList) {
            throw $this->createNotFoundException('Liste introuvable');
        }

        // Vérifier que c'est bien sa liste ou qu'elle est publique
        if ($armyList->getOwner() !== $user && !$armyList->isPublic()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('army/show.html.twig', [
            'armyList' => $armyList,
            'isOwner' => $armyList->getOwner() === $user,
        ]);
    }

    // Modifier une liste
    #[Route('/{id}/edit', name: 'edit')]
    public function edit(int $id,Request $request,ArmyListRepository $armyListRepository,EntityManagerInterface $em): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $armyList = $armyListRepository->find($id);

        if (!$armyList || $armyList->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $armyList->setName($request->request->get('name'));
            $armyList->setDetachment($request->request->get('detachment') ?: null);
            $armyList->setDescription($request->request->get('description') ?: null);
            $armyList->setIsPublic($request->request->get('isPublic') === '1');
            $armyList->setUpdatedAt(new \DateTimeImmutable());

            // La faction n'est volontairement pas modifiable ici — voir armyList.faction, jamais lue depuis la requête

            $unitsJson = $request->request->get('units_json', '[]');
            $unitsData = json_decode($unitsJson, true) ?: [];

            // On vide la collection existante : grâce à orphanRemoval, Doctrine supprimera
            // en base les ArmyUnit qui ne sont plus rattachés à la liste au flush().
            foreach ($armyList->getUnits()->toArray() as $existingUnit) {
                $armyList->removeUnit($existingUnit);
            }

            $totalPoints = 0;
            foreach ($unitsData as $unitData) {
                $unit = new ArmyUnit();
                $unit->setName($unitData['name']);
                $unit->setQuantity((int) $unitData['quantity']);
                $unit->setCategory($unitData['category'] ?? null);
                $unit->setStatsData($unitData['statsData'] ?? null);
                $unit->setPoints((int) $unitData['points']);
                $armyList->addUnit($unit);
                $totalPoints += (int) $unitData['points'] * (int) $unitData['quantity'];
            }
            $armyList->setTotalPoints($totalPoints);

            $em->flush();

            $this->addFlash('success', 'Liste mise à jour !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }

        // On prépare les unités existantes pour l'initialisation d'Alpine.js côté template
        $initialUnits = array_map(fn($u) => [
            'name' => $u->getName(),
            'points' => $u->getPoints(),
            'quantity' => $u->getQuantity(),
            'category' => $u->getCategory(),
        ], $armyList->getUnits()->toArray());

        return $this->render('army/edit.html.twig', [
            'armyList' => $armyList,
            'initialUnitsJson' => json_encode($initialUnits),
        ]);
    }

    // Supprimer une liste
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        int $id,
        ArmyListRepository $armyListRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $armyList = $armyListRepository->find($id);

        if (!$armyList || $armyList->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($armyList);
        $em->flush();

        $this->addFlash('success', 'Liste supprimée.');
        return $this->redirectToRoute('app_army_index');
    }

}