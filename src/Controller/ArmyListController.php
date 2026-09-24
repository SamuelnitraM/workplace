<?php

namespace App\Controller;

use App\Army\UnitCategory;
use App\Entity\ArmyList;
use App\Entity\ArmyUnit;
use App\Entity\FactionUnit;
use App\Repository\ArmyListRepository;
use App\Repository\FactionDetachementRepository;
use App\Repository\FactionUnitRepository;
use App\Security\Voter\ArmyListVoter;
use App\Service\BsDataFetcher;
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
    private const CSRF_FORM = 'army_list';
    private const NAME_MAX_LENGTH = 255;   // ArmyList.name
    private const DETACHMENT_MAX_LENGTH = 155; // ArmyList.detachment
    private const MAX_UNITS = 200;
    private const MAX_QUANTITY = 99;

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
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        FactionUnitRepository $factionUnitRepository,
        FactionDetachementRepository $detachementRepository,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_FORM, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');
                return $this->redirectToRoute('app_army_new');
            }

            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            $faction = (string) $request->request->get('faction', '');
            if (!array_key_exists($faction, BsDataFetcher::FACTION_FILES)) {
                $this->addFlash('error', 'Faction inconnue.');
                return $this->redirectToRoute('app_army_new');
            }

            $armyList = new ArmyList();
            $armyList->setFaction($faction);
            $armyList->setOwner($user);

            $error = $this->applyFormData($request, $armyList, $factionUnitRepository, $detachementRepository);
            if ($error !== null) {
                $this->addFlash('error', $error);
                return $this->redirectToRoute('app_army_new');
            }

            $em->persist($armyList);
            $em->flush();

            $this->addFlash('success', 'Liste d\'armée créée avec succès !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }

        return $this->render('army/new.html.twig', [
            'unitGroups' => UnitCategory::all(),
            'factionGroups' => BsDataFetcher::factionGroups(),
        ]);
    }

    #[Route('/units/{faction}', name: 'units_by_faction', methods: ['GET'])]
    public function unitsByFaction(string $faction, FactionUnitRepository $factionUnitRepository): JsonResponse
    {
        $units = $factionUnitRepository->findBy(['faction' => $faction], ['name' => 'ASC']);

        // Groupe calculé côté serveur (UnitCategory) : le JS se contente de grouper / trier par `group` + `groupOrder`
        return $this->json(array_map(function (FactionUnit $unit) {
            $group = UnitCategory::resolveFromStats($unit->getCategory(), $unit->getStatsData());

            return [
                'id' => $unit->getId(),
                'name' => $this->displayName($unit),
                'nameEn' => $unit->getName(),
                'points' => $unit->getPoints(),
                'category' => $unit->getCategory(),
                'group' => $group,
                'groupLabel' => UnitCategory::label($group),
                'groupOrder' => UnitCategory::order($group),
                'statsData' => $unit->getStatsData(),
            ];
        }, $units));
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
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, ArmyListRepository $armyListRepository, FactionUnitRepository $factionUnitRepository): Response
    {
        $armyList = $armyListRepository->find($id);

        if (!$armyList) {
            throw $this->createNotFoundException('Liste introuvable');
        }

        // Vérifier que c'est bien sa liste ou qu'elle est publique
        $this->denyAccessUnlessGranted(ArmyListVoter::VIEW, $armyList);

        // Unités regroupées par type (UnitCategory), groupes vides exclus, dans l'ordre d'affichage
        $unitGroups = [];
        foreach ($this->resolveUnitGroups($armyList, $factionUnitRepository) as $entry) {
            $key = $entry['group'];
            $unit = $entry['unit'];
            $unitGroups[$key] ??= ['key' => $key, 'units' => [], 'count' => 0, 'points' => 0] + UnitCategory::GROUPS[$key];
            $unitGroups[$key]['units'][] = $unit;
            $unitGroups[$key]['count'] += $unit->getQuantity();
            $unitGroups[$key]['points'] += $unit->getPoints() * $unit->getQuantity();
        }
        uksort($unitGroups, fn(string $a, string $b) => UnitCategory::order($a) <=> UnitCategory::order($b));

        return $this->render('army/show.html.twig', [
            'armyList' => $armyList,
            'unitGroups' => array_values($unitGroups),
        ]);
    }

    // Modifier une liste
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        ArmyListRepository $armyListRepository,
        FactionUnitRepository $factionUnitRepository,
        FactionDetachementRepository $detachementRepository,
        EntityManagerInterface $em,
    ): Response {
        $armyList = $armyListRepository->find($id);

        if (!$armyList) {
            throw $this->createNotFoundException('Liste introuvable');
        }
        $this->denyAccessUnlessGranted(ArmyListVoter::EDIT, $armyList);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_FORM, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');
                return $this->redirectToRoute('app_army_edit', ['id' => $armyList->getId()]);
            }

            // La faction n'est volontairement pas modifiable ici — voir armyList.faction, jamais lue depuis la requête
            $error = $this->applyFormData($request, $armyList, $factionUnitRepository, $detachementRepository);
            if ($error !== null) {
                // Rien n'est flushé : les modifications partielles en mémoire sont abandonnées
                $this->addFlash('error', $error);
                return $this->redirectToRoute('app_army_edit', ['id' => $armyList->getId()]);
            }

            $armyList->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            $this->addFlash('success', 'Liste mise à jour !');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }

        // On prépare les unités existantes pour l'initialisation du formulaire (composant Alpine.js « armyForm »).
        // armyUnitId permet au serveur de retrouver l'unité déjà enregistrée (et ses stats) à la sauvegarde.
        $initialUnits = [];
        foreach ($this->resolveUnitGroups($armyList, $factionUnitRepository) as $entry) {
            $u = $entry['unit'];
            $initialUnits[] = [
                'armyUnitId' => $u->getId(),
                'name' => $u->getName(),
                'points' => $u->getPoints(),
                'quantity' => $u->getQuantity(),
                'category' => $u->getCategory(),
                'group' => $entry['group'],
                'groupLabel' => UnitCategory::label($entry['group']),
                'groupOrder' => UnitCategory::order($entry['group']),
                'statsData' => $entry['statsData'],
            ];
        }

        return $this->render('army/edit.html.twig', [
            'armyList' => $armyList,
            // Passé au contrôleur Stimulus army-form (valeur JSON échappée dans un attribut data-*)
            'initialUnits' => $initialUnits,
            'unitGroups' => UnitCategory::all(),
        ]);
    }

    // Supprimer une liste
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        ArmyListRepository $armyListRepository,
        EntityManagerInterface $em
    ): Response {
        $armyList = $armyListRepository->find($id);

        if (!$armyList) {
            throw $this->createNotFoundException('Liste introuvable');
        }
        $this->denyAccessUnlessGranted(ArmyListVoter::DELETE, $armyList);

        if (!$this->isCsrfTokenValid('army_delete_' . $armyList->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');
            return $this->redirectToRoute('app_army_show', ['id' => $armyList->getId()]);
        }

        $em->remove($armyList);
        $em->flush();

        $this->addFlash('success', 'Liste supprimée.');
        return $this->redirectToRoute('app_army_index');
    }

    /**
     * Valide et applique les champs du formulaire (nom, détachement, description, visibilité, unités).
     * Les données des unités (nom, points, catégorie, stats) viennent TOUJOURS de la base,
     * jamais du client : le client n'envoie que des identifiants et des quantités.
     *
     * @return string|null message d'erreur, ou null si tout est valide
     */
    private function applyFormData(
        Request $request,
        ArmyList $armyList,
        FactionUnitRepository $factionUnitRepository,
        FactionDetachementRepository $detachementRepository,
    ): ?string {
        $faction = $armyList->getFaction();

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            return 'Le nom de la liste est obligatoire.';
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return sprintf('Le nom de la liste ne doit pas dépasser %d caractères.', self::NAME_MAX_LENGTH);
        }

        $detachment = trim((string) $request->request->get('detachment', ''));
        // On tolère le détachement déjà enregistré même s'il a disparu des données BSData depuis
        if ($detachment !== '' && $detachment !== $armyList->getDetachment()) {
            if (mb_strlen($detachment) > self::DETACHMENT_MAX_LENGTH
                || !$detachementRepository->findOneBy(['faction' => $faction, 'name' => $detachment])
            ) {
                return 'Détachement invalide pour cette faction.';
            }
        }

        $description = trim((string) $request->request->get('description', ''));

        // --- Unités ---
        try {
            $unitsData = json_decode((string) $request->request->get('units_json', '[]'), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'La composition de la liste est illisible, merci de réessayer.';
        }
        if (!is_array($unitsData) || !array_is_list($unitsData)) {
            return 'La composition de la liste est invalide.';
        }
        if (count($unitsData) > self::MAX_UNITS) {
            return sprintf('Une liste ne peut pas contenir plus de %d entrées.', self::MAX_UNITS);
        }

        // Unités déjà enregistrées dans cette liste, indexées par id (édition)
        $existingUnits = [];
        foreach ($armyList->getUnits() as $existing) {
            if ($existing->getId() !== null) {
                $existingUnits[$existing->getId()] = $existing;
            }
        }

        $keptUnits = [];
        $newUnits = [];
        $totalPoints = 0;

        foreach ($unitsData as $unitData) {
            if (!is_array($unitData)) {
                return 'La composition de la liste est invalide.';
            }

            $quantity = filter_var($unitData['quantity'] ?? 1, FILTER_VALIDATE_INT);
            $quantity = max(1, min(self::MAX_QUANTITY, $quantity === false ? 1 : $quantity));

            $armyUnitId = filter_var($unitData['armyUnitId'] ?? null, FILTER_VALIDATE_INT);
            $factionUnitId = filter_var($unitData['factionUnitId'] ?? null, FILTER_VALIDATE_INT);

            if ($armyUnitId !== false && $armyUnitId !== null && isset($existingUnits[$armyUnitId])) {
                // Unité déjà présente : on garde ses données enregistrées, seule la quantité change
                $unit = $existingUnits[$armyUnitId];
                if (isset($keptUnits[$armyUnitId])) {
                    return 'La composition de la liste est invalide.';
                }
                $unit->setQuantity($quantity);
                if ($unit->getStatsData() === null) {
                    $match = $this->findFactionUnitByName($factionUnitRepository, $faction, $unit->getName());
                    $unit->setStatsData($match?->getStatsData());
                }
                $keptUnits[$armyUnitId] = $unit;
            } elseif ($factionUnitId !== false && $factionUnitId !== null) {
                $factionUnit = $factionUnitRepository->find($factionUnitId);
                if (!$factionUnit || $factionUnit->getFaction() !== $faction) {
                    return 'Une des unités sélectionnées n\'appartient pas à cette faction.';
                }

                $unit = new ArmyUnit();
                $unit->setName($this->displayName($factionUnit));
                $unit->setPoints($factionUnit->getPoints() ?? 0);
                $unit->setCategory($factionUnit->getCategory());
                $unit->setStatsData($factionUnit->getStatsData());
                $unit->setQuantity($quantity);
                $newUnits[] = $unit;
            } else {
                return 'Une des unités sélectionnées est introuvable.';
            }

            $totalPoints += $unit->getPoints() * $quantity;
        }

        // Tout est valide : on applique
        $armyList->setName($name);
        $armyList->setDetachment($detachment !== '' ? $detachment : null);
        $armyList->setDescription($description !== '' ? $description : null);
        $armyList->setIsPublic($request->request->get('isPublic') === '1');

        // orphanRemoval : les ArmyUnit retirées de la collection sont supprimées au flush()
        foreach ($existingUnits as $existingId => $existing) {
            if (!isset($keptUnits[$existingId])) {
                $armyList->removeUnit($existing);
            }
        }
        foreach ($newUnits as $unit) {
            $armyList->addUnit($unit);
        }
        $armyList->setTotalPoints($totalPoints);

        return null;
    }

    /**
     * Groupe (UnitCategory) de chaque unité d'une liste, dans l'ordre de la liste.
     * Mots-clés lus dans le statsData copié à l'enregistrement ; pour les unités anciennes sans statsData,
     * on retrouve la FactionUnit correspondante (nom anglais ou français) en UNE requête ; sinon repli
     * sur la catégorie enregistrée.
     *
     * @return list<array{unit: ArmyUnit, group: string, statsData: ?array}>
     */
    private function resolveUnitGroups(ArmyList $armyList, FactionUnitRepository $repo): array
    {
        $missing = [];
        foreach ($armyList->getUnits() as $u) {
            if (!is_array($u->getStatsData()['keywords'] ?? null)) {
                $missing[] = $u->getName();
            }
        }

        $byName = [];
        if ($missing !== [] && $armyList->getFaction() !== null) {
            $matches = $repo->createQueryBuilder('f')
                ->where('f.faction = :faction')
                ->andWhere('f.name IN (:names) OR f.nameFr IN (:names)')
                ->setParameter('faction', $armyList->getFaction())
                ->setParameter('names', array_values(array_unique($missing)))
                ->getQuery()
                ->getResult();
            foreach ($matches as $match) {
                // Le nom anglais exact l'emporte sur le nom français
                if ($match->getNameFr() !== null) {
                    $byName[$match->getNameFr()] ??= $match;
                }
                $byName[$match->getName()] = $match;
            }
        }

        $entries = [];
        foreach ($armyList->getUnits() as $u) {
            $statsData = $u->getStatsData();
            $lookupStats = isset($byName[$u->getName()]) ? $byName[$u->getName()]->getStatsData() : null;
            $entries[] = [
                'unit' => $u,
                'group' => UnitCategory::resolveFromStats($u->getCategory(), is_array($statsData['keywords'] ?? null) ? $statsData : $lookupStats),
                'statsData' => $statsData ?? $lookupStats,
            ];
        }

        return $entries;
    }

    private function displayName(FactionUnit $unit): string
    {
        return $unit->getNameFr() ?: (string) $unit->getName();
    }

    /**
     * Retrouve une FactionUnit à partir du nom enregistré dans une ArmyUnit
     * (nom affiché = nameFr si présent, sinon nom anglais).
     */
    private function findFactionUnitByName(FactionUnitRepository $repo, ?string $faction, ?string $name): ?FactionUnit
    {
        if ($faction === null || $name === null) {
            return null;
        }

        return $repo->findOneBy(['faction' => $faction, 'name' => $name])
            ?? $repo->findOneBy(['faction' => $faction, 'nameFr' => $name]);
    }
}
